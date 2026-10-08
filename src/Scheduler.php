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

    public const OPTION_THROW_EXCEPTION = 1;

    public const OPTION_DEFAULT_AT = 'default_at';
    public const OPTION_DEFAULT_INTERVAL = 'default_interval';
    public const OPTION_DEFAULT_INTERVAL_REFERENCE = 'default_interval_reference';
    public const OPTION_DEFAULT_RETRY = 'default_retry';
    public const OPTION_DEFAULT_RETRY_INTERVAL = 'default_retry_interval';
    public const OPTION_DEFAULT_TIMEOUT = 'default_timeout';

    public const OPTION_PROGRESS_RATE_LIMIT = 'progress_rate_limit';
    public const OPTION_ORPHANED_RATE_LIMIT = 'orphaned_rate_limit';

    public const TYPE_MAP = [
        'document' => 'array',
        'root' => 'array',
        'array' => 'array',
    ];

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

    protected $db;

    protected $logger;

    protected $job_queue = 'taskscheduler';

    protected $default_at = 0;

    protected $default_interval = 0;

    protected $default_retry = 0;

    protected $default_retry_interval = 300;

    protected $default_timeout = 0;

    protected $default_interval_reference = 'end';

    protected $progress_rate_limit = 1000;

    protected $orphaned_rate_limit = 30;

    protected $progress_limit = [];

    protected $sessionHandler;

    public function __construct(
        Database $db,
        LoggerInterface $logger,
        array $config = [],
        ?Emitter $emitter = null
    ) {
        $this->db = $db;
        $this->logger = $logger;
        $this->sessionHandler = new SessionHandler(
            $this->db,
            $this->logger
        );
        $this->setOptions($config);
        $this->emitter = $emitter ?? new Emitter();
    }

    public function setOptions(array $config = []): self
    {
        foreach ($config as $option => $value) {
            switch ($option) {
                case self::OPTION_JOB_QUEUE:
                    if (!is_string($value) || $value === '') {
                        throw new InvalidArgumentException(
                            $option . ' needs to be a non-empty string'
                        );
                    }

                    $this->{$option} = $value;

                    break;

                case self::OPTION_DEFAULT_INTERVAL_REFERENCE:
                    if (!in_array($value, ['start', 'end'], true)) {
                        throw new InvalidArgumentException(
                            $option . ' must be either "start" or "end"'
                        );
                    }

                    $this->{$option} = $value;

                    break;

                case self::OPTION_DEFAULT_AT:
                case self::OPTION_DEFAULT_RETRY_INTERVAL:
                case self::OPTION_DEFAULT_INTERVAL:
                case self::OPTION_DEFAULT_RETRY:
                case self::OPTION_DEFAULT_TIMEOUT:
                case self::OPTION_PROGRESS_RATE_LIMIT:
                case self::OPTION_ORPHANED_RATE_LIMIT:
                    if (!is_int($value)) {
                        throw new InvalidArgumentException(
                            $option . ' needs to be an integer'
                        );
                    }

                    $this->{$option} = $value;

                    break;

                default:
                    throw new InvalidArgumentException(
                        'invalid option ' . $option . ' given'
                    );
            }
        }

        if ($this->progress_rate_limit < 0) {
            throw new InvalidArgumentException(
                'progress_rate_limit must not be negative'
            );
        }

        if ($this->orphaned_rate_limit < 0) {
            throw new InvalidArgumentException(
                'orphaned_rate_limit must not be negative'
            );
        }

        return $this;
    }

    public function getProgressRateLimit(): int
    {
        return $this->progress_rate_limit;
    }

    public function getOrphanedRateLimit(): int
    {
        return $this->orphaned_rate_limit;
    }

    public function getJobQueue(): string
    {
        return $this->job_queue;
    }

    public function getJob(ObjectId $id): Process
    {
        $result = $this->db->{$this->job_queue}->findOne(
            ['_id' => $id],
            ['typeMap' => self::TYPE_MAP]
        );

        if ($result === null) {
            throw new JobNotFoundException(
                'job ' . $id . ' was not found'
            );
        }

        return new Process($result, $this);
    }

    public function cancelJob(ObjectId $id): bool
    {
        $result = $this->updateJob(
            $id,
            JobInterface::STATUS_CANCELED
        );

        if ($result->getMatchedCount() !== 1) {
            throw new JobNotFoundException(
                'job ' . $id . ' was not found'
            );
        }

        return true;
    }

    public function flush(): self
    {
        $this->db->{$this->job_queue}->drop();

        return $this;
    }

    public function getJobs(array $query = []): Generator
    {
        if (count($query) === 0) {
            $query = [
                'status' => [
                    '$in' => [
                        JobInterface::STATUS_WAITING,
                        JobInterface::STATUS_PROCESSING,
                        JobInterface::STATUS_POSTPONED,
                    ],
                ],
            ];
        }

        $result = $this->db->{$this->job_queue}->find(
            $query,
            ['typeMap' => self::TYPE_MAP]
        );

        foreach ($result as $job) {
            yield new Process($job, $this);
        }
    }

    public function addJob(string $class, $data, array $options = []): Process
    {
        $document = $this->prepareInsert(
            $class,
            $data,
            $options
        );

        $result = $this->db->{$this->job_queue}->insertOne(
            $document
        );

        $this->logger->debug(
            'queue job [' . $result->getInsertedId() . '] added to [' . $class . ']',
            [
                'category' => get_class($this),
                'params' => $options,
                'data' => $data,
            ]
        );

        $document = $this->db->{$this->job_queue}->findOne(
            ['_id' => $result->getInsertedId()],
            ['typeMap' => self::TYPE_MAP]
        );

        if ($document === null) {
            throw new JobNotFoundException(
                'inserted job could not be loaded again'
            );
        }

        $process = new Process($document, $this);

        $this->emit($process);

        return $process;
    }

    public function addJobOnce(
        string $class,
               $data,
        array $options = []
    ): ?Process {
        $document = $this->prepareInsert(
            $class,
            $data,
            $options
        );

        $filter = [
            'class' => $class,
            'status' => [
                '$in' => [
                    JobInterface::STATUS_WAITING,
                    JobInterface::STATUS_POSTPONED,
                    JobInterface::STATUS_PROCESSING,
                ],
            ],
        ];

        if (!$document['options'][self::OPTION_IGNORE_DATA]) {
            $filter['data'] = $data;
        }

        $result = $this->db->{$this->job_queue}->updateOne(
            $filter,
            ['$setOnInsert' => $document],
            ['upsert' => true]
        );

        if ($result->getUpsertedId() !== null) {
            $inserted = $this->db->{$this->job_queue}->findOne(
                ['_id' => $result->getUpsertedId()],
                ['typeMap' => self::TYPE_MAP]
            );

            if ($inserted === null) {
                return null;
            }

            $this->logger->debug(
                'queue job [' . $result->getUpsertedId() . '] added to [' . $class . ']',
                [
                    'category' => get_class($this),
                    'params' => $options,
                    'data' => $data,
                ]
            );

            $process = new Process($inserted, $this);
            $this->emit($process);

            return $process;
        }

        $existing = $this->db->{$this->job_queue}->findOne(
            $filter,
            ['typeMap' => self::TYPE_MAP]
        );

        if ($existing === null) {
            return null;
        }

        $process = new Process($existing, $this);

        if ($this->jobNeedsReschedule(
            $process,
            $document['options'],
            $data
        )) {
            $this->cancelJob($process->getId());

            return $this->addJobOnce(
                $class,
                $data,
                $options
            );
        }

        return $process;
    }

    public function waitFor(
        array $stack,
        int $options = 0
    ): self {
        if (count($stack) === 0) {
            return $this;
        }

        $orig = [];
        $jobs = [];

        foreach ($stack as $job) {
            if (!$job instanceof Process) {
                throw new InvalidArgumentException(
                    'waitFor() requires a stack of Process[]'
                );
            }

            $id = (string) $job->getId();

            $orig[$id] = $job;
            $jobs[] = $job->getId();
        }

        $collection = $this->db->{$this->getJobQueue()};

        /*
         * First check the current state. Otherwise waitFor() can wait
         * forever when the job already finished before watch() started.
         */
        foreach ($jobs as $jobId) {
            $current = $collection->findOne(
                ['_id' => $jobId],
                ['typeMap' => self::TYPE_MAP]
            );

            if ($current === null) {
                throw new JobNotFoundException(
                    'job ' . $jobId . ' was not found'
                );
            }

            if ((int) $current['status'] >= JobInterface::STATUS_DONE) {
                $this->applyWaitForResult(
                    $orig[(string) $jobId],
                    $current,
                    $options
                );
            }
        }

        $doneIds = [];

        foreach ($jobs as $jobId) {
            $current = $collection->findOne(
                ['_id' => $jobId],
                ['typeMap' => self::TYPE_MAP]
            );

            if (
                $current !== null
                && (int) $current['status'] >= JobInterface::STATUS_DONE
            ) {
                $doneIds[(string) $jobId] = true;
            }
        }

        if (count($doneIds) >= count($jobs)) {
            return $this;
        }

        $cursor = $collection->watch(
            [
                [
                    '$match' => [
                        'fullDocument._id' => [
                            '$in' => $jobs,
                        ],
                    ],
                ],
            ],
            [
                'fullDocument' => 'updateLookup',
                'maxAwaitTimeMS' => 1000,
            ]
        );

        $cursor->rewind();

        while ($this->loop()) {
            if (!$cursor->valid()) {
                $cursor->next();
                continue;
            }

            $event = $cursor->current();

            if (
                $event === null
                || !isset($event['fullDocument'])
            ) {
                $cursor->next();
                continue;
            }

            $fullDocument = (array) $event['fullDocument'];
            $id = (string) $fullDocument['_id'];

            if (!isset($orig[$id])) {
                $cursor->next();
                continue;
            }

            $this->applyWaitForResult(
                $orig[$id],
                $fullDocument,
                $options
            );

            if ((int) $fullDocument['status'] >= JobInterface::STATUS_DONE) {
                $doneIds[$id] = true;
            }

            $cursor->next();

            if (count($doneIds) >= count($jobs)) {
                return $this;
            }
        }

        return $this;
    }

    public function listen(
        Closure $callback,
        array $query = []
    ): self {
        $pipeline = [];

        if (count($query) > 0) {
            $pipeline[] = ['$match' => $query];
        }

        $cursor = $this->db->{$this->getJobQueue()}->watch(
            $pipeline,
            [
                'fullDocument' => 'updateLookup',
                'maxAwaitTimeMS' => 1000,
            ]
        );

        $cursor->rewind();

        while ($this->loop()) {
            if (!$cursor->valid()) {
                $cursor->next();
                continue;
            }

            $result = $cursor->current();

            if ($result === null || !isset($result['fullDocument'])) {
                $cursor->next();
                continue;
            }

            $process = new Process(
                (array) $result['fullDocument'],
                $this
            );

            $this->emit($process);

            if ($callback($process) === true) {
                return $this;
            }

            $cursor->next();
        }

        return $this;
    }

    public function emitEvent(Process $process): void
    {
        $this->emit($process);
    }

    public function updateJobProgress(
        JobInterface $job,
        float $progress
    ): self {
        if ($progress < 0 || $progress > 100) {
            throw new LogicException(
                'progress may only be between 0 to 100'
            );
        }

        $current = microtime(true);
        $jobId = (string) $job->getId();

        if (
            isset($this->progress_limit[$jobId])
            && (
                $this->progress_limit[$jobId]
                + ($this->progress_rate_limit / 1000)
            ) > $current
        ) {
            return $this;
        }

        $result = $this->db->{$this->job_queue}->updateOne(
            [
                '_id' => $job->getId(),
                'status' => JobInterface::STATUS_PROCESSING,
            ],
            [
                '$set' => [
                    'alive' => new UTCDateTime(),
                    'progress' => round($progress, 2),
                ],
            ]
        );

        if ($result->getMatchedCount() === 0) {
            return $this;
        }

        $data = $job->getData();

        if (
            is_array($data)
            && isset($data['parent'])
            && $data['parent'] instanceof ObjectId
        ) {
            $this->db->{$this->job_queue}->updateOne(
                [
                    '_id' => $data['parent'],
                    'status' => JobInterface::STATUS_PROCESSING,
                    'alive' => ['$exists' => true],
                ],
                [
                    '$set' => [
                        'alive' => new UTCDateTime(),
                    ],
                ]
            );
        }

        $this->progress_limit[$jobId] = $current;

        return $this;
    }

    public function getChildProcs(ObjectId $parentId): Generator
    {
        $result = $this->db->{$this->job_queue}->find(
            ['data.parent' => $parentId],
            ['typeMap' => self::TYPE_MAP]
        );

        foreach ($result as $job) {
            yield new Process($job, $this);
        }
    }

    public function getOrphanedProcs(
        UTCDateTime $aliveTime
    ): Generator {
        $result = $this->db->{$this->job_queue}->find(
            [
                'status' => JobInterface::STATUS_PROCESSING,
                'alive' => ['$lt' => $aliveTime],
                '$or' => [
                    ['data.parent' => ['$exists' => false]],
                    ['data.parent' => null],
                ],
            ],
            ['typeMap' => self::TYPE_MAP]
        );

        foreach ($result as $job) {
            yield new Process($job, $this);
        }
    }

    public function setJobOptionsType(
        array $options = []
    ): array {
        foreach ($options as $option => $value) {
            switch ($option) {
                case self::OPTION_AT:
                case self::OPTION_INTERVAL:
                case self::OPTION_RETRY:
                case self::OPTION_RETRY_INTERVAL:
                case self::OPTION_TIMEOUT:
                    $options[$option] = (int) $value;
                    break;

                case self::OPTION_IGNORE_DATA:
                case self::OPTION_FORCE_SPAWN:
                    $options[$option] = (bool) $value;
                    break;

                case self::OPTION_INTERVAL_REFERENCE:
                    $options[$option] = (string) $value;
                    break;

                default:
                    break;
            }
        }

        return $options;
    }

    protected function prepareInsert(
        string $class,
               $data,
        array &$options = []
    ): array {
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
            if (!$options[self::OPTION_ID] instanceof ObjectId) {
                throw new InvalidArgumentException(
                    'option id must be an ObjectId'
                );
            }

            $document['_id'] = $options[self::OPTION_ID];

            unset($options[self::OPTION_ID]);
        }

        $document['options'] = $options;

        return $document;
    }

    protected function updateJob(
        ObjectId $id,
        int $status
    ): UpdateResult {
        return $this->db->{$this->job_queue}->updateOne(
            ['_id' => $id],
            [
                '$set' => [
                    'status' => $status,
                ],
            ]
        );
    }

    protected function jobExists(ObjectId $id): bool
    {
        return $this->db->{$this->job_queue}->findOne(
                ['_id' => $id],
                ['projection' => ['_id' => 1]]
            ) !== null;
    }

    protected function jobNeedsReschedule(
        Process $process,
        array $requestedOptions,
                $requestedData
    ): bool {
        $existingOptions = $process->getOptions();

        foreach ($requestedOptions as $key => $value) {
            if (
                !array_key_exists($key, $existingOptions)
                || $existingOptions[$key] != $value
            ) {
                return true;
            }
        }

        if (
            !$requestedOptions[self::OPTION_IGNORE_DATA]
            && $process->getData() != $requestedData
        ) {
            return true;
        }

        return false;
    }

    protected function applyWaitForResult(
        Process $process,
        array $document,
        int $options
    ): void {
        $data = $process->toArray();
        $data['status'] = (int) $document['status'];

        if (isset($document['progress'])) {
            $data['progress'] = (float) $document['progress'];
        }

        if (isset($document['worker'])) {
            $data['worker'] = $document['worker'];
        }

        $process->replace(
            new Process($data, $this)
        );

        $this->emit($process);

        if (
            (int) $document['status'] === JobInterface::STATUS_FAILED
            && ($options & self::OPTION_THROW_EXCEPTION)
            && isset($document['exception']['class'])
        ) {
            $exceptionClass = $document['exception']['class'];
            $message = $document['exception']['message'] ?? '';
            $code = isset($document['exception']['code'])
                ? (int) $document['exception']['code']
                : 0;

            if (is_a($exceptionClass, \Throwable::class, true)) {
                throw new $exceptionClass($message, $code);
            }

            throw new \RuntimeException(
                'Job failed with invalid exception class: ' . $exceptionClass
            );
        }
    }
}
