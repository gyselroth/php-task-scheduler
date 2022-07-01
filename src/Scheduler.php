<?php

declare(strict_types=1);

/**
 * TaskScheduler
 *
 * @author      gyselroth™  (http://www.gyselroth.com)
 * @copyright   Copryright (c) 2017-2022 gyselroth GmbH (https://gyselroth.com)
 * @license     MIT https://opensource.org/licenses/MIT
 */

namespace TaskScheduler;

use Closure;
use Generator;
use League\Event\Emitter;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Database;
use MongoDB\UpdateResult;
use Psr\Log\LoggerInterface;
use TaskScheduler\Exception\InvalidArgumentException;
use TaskScheduler\Exception\JobNotFoundException;
use TaskScheduler\Exception\LogicException;

class Scheduler
{
    use EventsTrait;
    use InjectTrait;

    /**
     * Job options.
     */
    public const OPTION_AT = 'at';
    public const OPTION_INTERVAL = 'interval';
    public const OPTION_INTERVAL_REFERENCE = 'interval_reference';
    public const OPTION_RETRY = 'retry';
    public const OPTION_RETRY_INTERVAL = 'retry_interval';
    public const OPTION_FORCE_SPAWN = 'force_spawn';
    public const OPTION_TIMEOUT = 'timeout';
    public const OPTION_ID = 'id';
    public const OPTION_JOB_QUEUE = 'job_queue';
    public const OPTION_IGNORE_DATA = 'ignore_data';

    /**
     * Operation options:.
     */
    public const OPTION_THROW_EXCEPTION = 1;

    /**
     * Default job options.
     */
    public const OPTION_DEFAULT_AT = 'default_at';
    public const OPTION_DEFAULT_INTERVAL = 'default_interval';
    public const OPTION_DEFAULT_INTERVAL_REFERENCE = 'default_interval_reference';
    public const OPTION_DEFAULT_RETRY = 'default_retry';
    public const OPTION_DEFAULT_RETRY_INTERVAL = 'default_retry_interval';
    public const OPTION_DEFAULT_TIMEOUT = 'default_timeout';

    /**
     * Queue options.
     */
    public const OPTION_PROGRESS_RATE_LIMIT = 'progress_rate_limit';
    public const OPTION_ORPHANED_RATE_LIMIT = 'orphaned_rate_limit';

    /**
     * MongoDB type map.
     */
    public const TYPE_MAP = [
        'document' => 'array',
        'root' => 'array',
        'array' => 'array',
    ];

    /**
     * Valid events.
     */
    public const VALID_EVENTS = [
        'taskscheduler.onWaiting',
        'taskscheduler.onPostponed',
        'taskscheduler.onProcessing',
        'taskscheduler.onDone',
        'taskscheduler.onFailed',
        'taskscheduler.onTimeout',
        'taskscheduler.onCancel',
        'taskscheduler.onWorkerSpawn',
        'taskscheduler.onWorkerKill',
    ];

    /**
     * Database.
     *
     * @var Database
     */
    protected $db;

    /**
     * LoggerInterface.
     *
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * Job Collection name.
     *
     * @var string
     */
    protected $job_queue = 'taskscheduler';

    /**
     * Unix time.
     *
     * @var int
     */
    protected $default_at = 0;

    /**
     * Default interval (secconds).
     *
     * @var int
     */
    protected $default_interval = 0;

    /**
     * Default retry.
     *
     * @var int
     */
    protected $default_retry = 0;

    /**
     * Default retry interval (secconds).
     *
     * @var int
     */
    protected $default_retry_interval = 300;

    /**
     * Default timeout.
     *
     * @var int
     */
    protected $default_timeout = 0;

    /**
     * Default interval reference (start/end).
     *
     * @var string
     */
    protected $default_interval_reference = 'end';

    /**
     * Progress rate limit (miliseconds).
     *
     * @var int
     */
    protected $progress_rate_limit = 1000;

    /**
     * Orphaned rate limit.
     *
     * @var int
     */
    protected $orphaned_rate_limit = 30;

    /**
     * Progress rate limit storage.
     *
     * @var array
     */
    protected $progress_limit = [];

    /**
     * SessionHandler.
     *
     * @var SessionHandler
     */
    protected $sessionHandler;

    /**
     * Init queue.
     */
    public function __construct(Database $db, LoggerInterface $logger, array $config = [], ?Emitter $emitter = null)
    {
        $this->db = $db;
        $this->logger = $logger;
        $this->sessionHandler = new SessionHandler($this->db, $this->logger);
        $this->setOptions($config);
        $this->emitter = $emitter ?? new Emitter();
    }

    /**
     * Set options.
     */
    public function setOptions(array $config = []): self
    {
        foreach ($config as $option => $value) {
            switch ($option) {
                case self::OPTION_JOB_QUEUE:
                case self::OPTION_DEFAULT_INTERVAL_REFERENCE:
                    $this->{$option} = (string) $value;

                    break;
                case self::OPTION_DEFAULT_AT:
                case self::OPTION_DEFAULT_RETRY_INTERVAL:
                case self::OPTION_DEFAULT_INTERVAL:
                case self::OPTION_DEFAULT_RETRY:
                case self::OPTION_DEFAULT_TIMEOUT:
                case self::OPTION_PROGRESS_RATE_LIMIT:
                case self::OPTION_ORPHANED_RATE_LIMIT:
                    $this->{$option} = (int) $value;

                    break;
                default:
                    throw new InvalidArgumentException('invalid option '.$option.' given');
            }
        }

        return $this;
    }

    /**
     * Get progress rate limit.
     */
    public function getProgressRateLimit(): int
    {
        return $this->progress_rate_limit;
    }

    /**
     * Get orphaned rate limit.
     */
    public function getOrphanedRateLimit(): int
    {
        return $this->orphaned_rate_limit;
    }

    /**
     * Get job collection name.
     */
    public function getJobQueue(): string
    {
        return $this->job_queue;
    }

    /**
     * Get job by Id.
     */
    public function getJob(ObjectId $id): Process
    {
        $result = $this->db->{$this->job_queue}->findOne([
            '_id' => $id,
        ], [
            'typeMap' => self::TYPE_MAP,
        ]);

        if (null === $result) {
            throw new JobNotFoundException('job '.$id.' was not found');
        }

        return new Process($result, $this);
    }

    /**
     * Cancel job.
     */
    public function cancelJob(ObjectId $id): bool
    {
        $result = $this->updateJob($id, JobInterface::STATUS_CANCELED);

        if (1 !== $result->getMatchedCount()) {
            throw new JobNotFoundException('job '.$id.' was not found');
        }

        return true;
    }

    /**
     * Flush.
     */
    public function flush(): Scheduler
    {
        $this->db->{$this->job_queue}->drop();

        return $this;
    }

    /**
     * Get jobs (Pass a filter which contains job status, by default all active jobs get returned).
     */
    public function getJobs(array $query = []): Generator
    {
        if (0 === count($query)) {
            $query = ['status' => ['$in' => [
                JobInterface::STATUS_WAITING,
                JobInterface::STATUS_PROCESSING,
                JobInterface::STATUS_POSTPONED,
            ]]];
        }

        $result = $this->db->{$this->job_queue}->find($query, [
            'typeMap' => self::TYPE_MAP,
        ]);

        foreach ($result as $job) {
            yield new Process($job, $this);
        }
    }

    /**
     * Add job to queue.
     */
    public function addJob(string $class, $data, array $options = []): Process
    {
        $document = $this->prepareInsert($class, $data, $options);

        $result = $this->db->{$this->job_queue}->insertOne($document);
        $this->logger->debug('queue job ['.$result->getInsertedId().'] added to ['.$class.']', [
            'category' => get_class($this),
            'params' => $options,
            'data' => $data,
        ]);

        $document = $this->db->{$this->job_queue}->findOne(['_id' => $result->getInsertedId()], [
            'typeMap' => self::TYPE_MAP,
        ]);

        $process = new Process($document, $this);
        $this->emit($process);

        return $process;
    }

    /**
     * Only add job if not in queue yet.
     */
    public function addJobOnce(string $class, $data, array $options = []): Process
    {
        $filter = [
            'class' => $class,
            '$or' => [
                ['status' => JobInterface::STATUS_WAITING],
                ['status' => JobInterface::STATUS_POSTPONED],
                ['status' => JobInterface::STATUS_PROCESSING],
            ],
        ];

        $requested = $options;
        $document = $this->prepareInsert($class, $data, $options);

        if (true !== $options[self::OPTION_IGNORE_DATA]) {
            $filter = ['data' => $data] + $filter;
        }

        $session = $this->sessionHandler->getSession();
        $session->startTransaction($this->sessionHandler->getOptions());

        $result = $this->db->{$this->job_queue}->updateOne($filter, ['$setOnInsert' => $document], [
            'upsert' => true,
        ]);

        $session->commitTransaction();

        if ($result->getMatchedCount() > 0) {
            $document = $this->db->{$this->job_queue}->findOne($filter, [
                'typeMap' => self::TYPE_MAP,
            ]);

            if (array_intersect_key($document['options'], $requested) !== $requested || ($data !== $document['data'] && true === $options[self::OPTION_IGNORE_DATA])) {
                $this->logger->debug('job ['.$document['_id'].'] options/data changed, reschedule new job', [
                    'category' => get_class($this),
                    'data' => $data,
                ]);

                $this->cancelJob($document['_id']);

                return $this->addJobOnce($class, $data, $options);
            }

            return new Process($document, $this);
        }

        $this->logger->debug('queue job ['.$result->getUpsertedId().'] added to ['.$class.']', [
            'category' => get_class($this),
            'params' => $options,
            'data' => $data,
        ]);

        $document = $this->db->{$this->job_queue}->findOne(['_id' => $result->getUpsertedId()], [
            'typeMap' => self::TYPE_MAP,
        ]);

        return new Process($document, $this);
    }

    /**
     * Wait for job beeing executed.
     *
     * @param Process[] $stack
     */
    public function waitFor(array $stack, int $options = 0): Scheduler
    {
        $orig = [];
        $jobs = array_map(function ($job) use (&$orig) {
            if (!($job instanceof Process)) {
                throw new InvalidArgumentException('waitFor() requires a stack of Process[]');
            }

            $orig[(string) $job->getId()] = $job;

            return $job->getId();
        }, $stack);

        $cursor = $this->db->{$this->getJobQueue()}->watch([
            ['$match' => ['fullDocument._id' => ['$in' => $jobs]]],
        ], ['fullDocument' => 'updateLookup']);

        $expected = count($stack);
        $done = 0;
        $cursor->rewind();

        while ($this->loop()) {
            if (!$cursor->valid()) {
                $cursor->next();

                continue;
            }

            $event = $cursor->current();

            if (null === $event) {
                continue;
            }
            $process = $orig[(string) $event['fullDocument']['_id']];
            $data = $process->toArray();
            $data['status'] = $event['fullDocument']['status'];
            $process->replace(new Process($data, $this));
            $this->emit($process);

            if ($event['fullDocument']['status'] < JobInterface::STATUS_DONE) {
                $cursor->next();

                continue;
            }

            if (JobInterface::STATUS_FAILED === $event['fullDocument']['status'] && isset($event['exception']) && $options & self::OPTION_THROW_EXCEPTION) {
                throw new $event['exception']['class']($event['exception']['message'], $event['exception']['code']);
            }

            ++$done;

            $cursor->next();

            if ($done >= $expected) {
                return $this;
            }
        }
    }

    /**
     * Listen for events.
     */
    public function listen(Closure $callback, array $query = []): self
    {
        if (count($query) > 0) {
            $query = [['$match' => $query]];
        }

        $cursor = $this->db->{$this->getJobQueue()}->watch($query, ['fullDocument' => 'updateLookup']);

        for ($cursor->rewind(); true; $cursor->next()) {
            if (!$cursor->valid()) {
                continue;
            }

            $result = $cursor->current();

            if (null === $result) {
                continue;
            }

            $result = json_decode(json_encode($result->bsonSerialize()), true);
            $process = new Process($result['fullDocument'], $this);
            $this->emit($process);

            if (true === $callback($process)) {
                return $this;
            }
        }
    }

    public function emitEvent(Process $process): void
    {
        $this->emit($process);
    }

    /**
     * Update job progress.
     */
    public function updateJobProgress(JobInterface $job, float $progress): self
    {
        if ($progress < 0 || $progress > 100) {
            throw new LogicException('progress may only be between 0 to 100');
        }

        $current = microtime(true);

        if (isset($this->progress_limit[(string) $job->getId()]) && $this->progress_limit[(string) $job->getId()] + $this->progress_rate_limit / 1000 > $current) {
            return $this;
        }

        $this->db->{$this->job_queue}->updateOne([
            '_id' => $job->getId(),
            'progress' => ['$exists' => true],
        ], [
            '$set' => [
                'alive' => new UTCDateTime(),
                'progress' => round($progress, 2),
            ],
        ]);

        if (isset($job->getData()['parent']) && $job->getData()['parent'] instanceof ObjectId && $this->jobExists($job->getData()['parent'])) {
            $this->db->{$this->job_queue}->updateOne([
                '_id' => $job->getData()['parent'],
                'alive' => ['$exists' => true],
            ], [
                '$set' => [
                    'alive' => new UTCDateTime(),
                ],
            ]);
        }

        $this->progress_limit[(string) $job->getId()] = $current;

        return $this;
    }

    /**
     * Get child jobs.
     */
    public function getChildProcs(ObjectId $parentId): Generator
    {
        $result = $this->db->{$this->job_queue}->find([
            'data.parent' => $parentId,
        ], [
            'typeMap' => self::TYPE_MAP,
        ]);

        foreach ($result as $job) {
            yield new Process($job, $this);
        }
    }

    public function getOrphanedProcs(UTCDateTime $alive_time): Generator
    {
        $result = $this->db->{$this->job_queue}->find([
            'status' => JobInterface::STATUS_PROCESSING,
            'alive' => ['$lt' => $alive_time],
            '$or' => [
                ['data.parent' => ['$exists' => false]],
                ['data.parent' => null],
            ],
        ], [
            'typeMap' => self::TYPE_MAP,
        ]);

        foreach ($result as $job) {
            yield new Process($job, $this);
        }
    }

    public function setJobOptionsType(array $options = []): array
    {
        foreach ($options as $option => $value) {
            switch ($option) {
                case Scheduler::OPTION_AT:
                case Scheduler::OPTION_INTERVAL:
                case Scheduler::OPTION_RETRY:
                case Scheduler::OPTION_RETRY_INTERVAL:
                case Scheduler::OPTION_TIMEOUT:
                    $options[$option] = (int)$value;

                    break;
                case Scheduler::OPTION_IGNORE_DATA:
                case Scheduler::OPTION_FORCE_SPAWN:
                    $options[$option] = (bool)$value;

                    break;
                default:
                    break;
            }
        }

        return $options;
    }

    /**
     * Prepare insert.
     */
    protected function prepareInsert(string $class, $data, array &$options = []): array
    {
        $defaults = [
            self::OPTION_AT => $this->default_at,
            self::OPTION_INTERVAL => $this->default_interval,
            self::OPTION_RETRY => $this->default_retry,
            self::OPTION_RETRY_INTERVAL => $this->default_retry_interval,
            self::OPTION_FORCE_SPAWN => false,
            self::OPTION_TIMEOUT => $this->default_timeout,
            self::OPTION_IGNORE_DATA => false,
            self::OPTION_INTERVAL_REFERENCE => $this->default_interval_reference,
        ];

        $options = array_merge($defaults, $options);
        $options = SchedulerValidator::validateOptions($options);

        $document = [
            'class' => $class,
            'status' => JobInterface::STATUS_WAITING,
            'created' => new UTCDateTime(),
            'started' => null,
            'ended' => null,
            'alive' => new UTCDateTime(),
            'worker' => null,
            'progress' => 0.0,
            'data' => $data,
        ];

        if (isset($options[self::OPTION_ID])) {
            $id = $options[self::OPTION_ID];
            unset($options[self::OPTION_ID]);
            $document['_id'] = $id;
        }

        $document['options'] = $options;

        return $document;
    }

    /**
     * Update job status.
     */
    protected function updateJob(ObjectId $id, int $status): UpdateResult
    {
        $session = $this->sessionHandler->getSession();
        $session->startTransaction($this->sessionHandler->getOptions());

        $result = $this->db->{$this->job_queue}->updateMany([
            '_id' => $id,
        ], [
            '$set' => [
                'status' => $status,
            ],
        ]);

        $session->commitTransaction();

        return $result;
    }

    /**
     * Check if job exists.
     */
    protected function jobExists(ObjectId $id): bool
    {
        $result = $this->db->{$this->job_queue}->findOne([
            '_id' => $id,
        ], [
            'typeMap' => self::TYPE_MAP,
        ]);

        return !(null === $result);
    }
}
