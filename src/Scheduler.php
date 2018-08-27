<?php

declare(strict_types=1);

/**
 * TaskScheduler
 *
 * @author      Raffael Sahli <sahli@gyselroth.net>
 * @copyright   Copryright (c) 2017-2018 gyselroth GmbH (https://gyselroth.com)
 * @license     MIT https://opensource.org/licenses/MIT
 */

namespace TaskScheduler;

use Closure;
use Generator;
use InvalidArgumentException;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Database;
use MongoDB\UpdateResult;
use Psr\Log\LoggerInterface;

class Scheduler
{
    /**
     * Job options.
     */
    public const OPTION_AT = 'at';
    public const OPTION_INTERVAL = 'interval';
    public const OPTION_RETRY = 'retry';
    public const OPTION_RETRY_INTERVAL = 'retry_interval';
    public const OPTION_IGNORE_MAX_CHILDREN = 'ignore_max_children';
    public const OPTION_DEFAULT_AT = 'default_at';
    public const OPTION_DEFAULT_INTERVAL = 'default_interval';
    public const OPTION_DEFAULT_RETRY = 'default_retry';
    public const OPTION_DEFAULT_RETRY_INTERVAL = 'default_retry_interval';
    public const OPTION_JOB_QUEUE = 'job_queue';
    public const OPTION_JOB_QUEUE_SIZE = 'job_queue_size';
    public const OPTION_EVENT_QUEUE = 'event_queue';
    public const OPTION_EVENT_QUEUE_SIZE = 'event_queue_size';

    /**
     * MongoDB type map.
     */
    public const TYPE_MAP = [
        'document' => 'array',
        'root' => 'array',
        'array' => 'array',
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
    protected $job_queue = 'takscheduler.jobs';

    /**
     * Event Collection name.
     *
     * @var string
     */
    protected $event_queue = 'takscheduler.events';

    /**
     * Default at (Secconds from now).
     *
     * @var int
     */
    protected $default_at = 0;

    /**
     * Default interval (secconds).
     *
     * @var int
     */
    protected $default_interval = -1;

    /**
     * Default retry.
     *
     * @var int
     */
    protected $default_retry = -1;

    /**
     * Default retry interval (secconds).
     *
     * @var int
     */
    protected $default_retry_interval = 300;

    /**
     * Job Queue size.
     *
     * @var int
     */
    protected $job_queue_size = 100000;

    /**
     * Event Queue size.
     *
     * @var int
     */
    protected $event_queue_size = 500000;

    /**
     * Events queue.
     *
     * @var MessageQueue
     */
    protected $events;

    /**
     * Init queue.
     */
    public function __construct(Database $db, LoggerInterface $logger, array $config = [])
    {
        $this->db = $db;
        $this->logger = $logger;
        $this->setOptions($config);
        $this->events = new MessageQueue($db, $this->getEventQueue(), $this->getEventQueueSize(), $logger);
    }

    /**
     * Set options.
     *
     *
     * @return Scheduler
     */
    public function setOptions(array $config = []): self
    {
        foreach ($config as $option => $value) {
            switch ($option) {
                case self::OPTION_JOB_QUEUE:
                case self::OPTION_EVENT_QUEUE:
                    $this->{$option} = (string) $value;

                break;
                case self::OPTION_DEFAULT_AT:
                case self::OPTION_DEFAULT_RETRY_INTERVAL:
                case self::OPTION_DEFAULT_INTERVAL:
                case self::OPTION_DEFAULT_RETRY:
                case self::OPTION_JOB_QUEUE_SIZE:
                case self::OPTION_EVENT_QUEUE_SIZE:
                    $this->{$option} = (int) $value;

                break;
                default:
                    throw new InvalidArgumentException('invalid option '.$option.' given');
            }
        }

        return $this;
    }

    /**
     * Get job Queue size.
     */
    public function getJobQueueSize(): int
    {
        return $this->job_queue_size;
    }

    /**
     * Get event Queue size.
     */
    public function getEventQueueSize(): int
    {
        return $this->event_queue_size;
    }

    /**
     * Get job collection name.
     */
    public function getJobQueue(): string
    {
        return $this->job_queue;
    }

    /**
     * Get event collection name.
     */
    public function getEventQueue(): string
    {
        return $this->event_queue;
    }

    /**
     * Get job by Id.
     *
     * @param ObjectId
     */
    public function getJob(ObjectId $id): Process
    {
        $result = $this->db->{$this->job_queue}->findOne([
            '_id' => $id,
        ], [
            'typeMap' => self::TYPE_MAP,
        ]);

        if (null === $result) {
            throw new Exception\JobNotFound('job '.$id.' was not found');
        }

        return new Process($result, $this, $this->events);
    }

    /**
     * Cancel job.
     */
    public function cancelJob(ObjectId $id): bool
    {
        $result = $this->updateJob($id, JobInterface::STATUS_CANCELED);

        if (1 !== $result->getModifiedCount()) {
            throw new Exception\JobNotFound('job '.$id.' was not found');
        }

        return true;
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
            yield new Process($job, $this, $this->events);
        }
    }

    /**
     * Add job to queue.
     */
    public function addJob(string $class, $data, array $options = []): Process
    {
        $defaults = [
            self::OPTION_AT => $this->default_at,
            self::OPTION_INTERVAL => $this->default_interval,
            self::OPTION_RETRY => $this->default_retry,
            self::OPTION_RETRY_INTERVAL => $this->default_retry_interval,
            self::OPTION_IGNORE_MAX_CHILDREN => false,
        ];

        $options = array_merge($defaults, $options);
        $this->validateOptions($options);
        $at = null;

        if ($options[self::OPTION_AT] > 0) {
            $at = new UTCDateTime($options[self::OPTION_AT] * 1000);
        }

        $result = $this->db->{$this->job_queue}->insertOne([
            'class' => $class,
            'status' => JobInterface::STATUS_WAITING,
            'created' => new UTCDateTime(),
            'started' => new UTCDateTime(0),
            'ended' => new UTCDateTime(0),
            'at' => $at,
            'retry' => $options[self::OPTION_RETRY],
            'retry_interval' => $options[self::OPTION_RETRY_INTERVAL],
            'interval' => $options[self::OPTION_INTERVAL],
            'ignore_max_children' => $options[self::OPTION_IGNORE_MAX_CHILDREN],
            'data' => $data,
        ], ['$isolated' => true]);

        $this->logger->debug('queue job ['.$result->getInsertedId().'] added to ['.$class.']', [
            'category' => get_class($this),
            'params' => $options,
            'data' => $data,
        ]);

        $this->db->{$this->event_queue}->insertOne([
            'job' => $result->getInsertedId(),
            'event' => JobInterface::STATUS_WAITING,
            'timestamp' => new UTCDateTime(),
        ]);

        $document = $this->db->{$this->job_queue}->findOne(['_id' => $result->getInsertedId()], [
            'typeMap' => self::TYPE_MAP,
        ]);

        $process = new Process($document, $this, $this->events);

        return $process;
    }

    /**
     * Only add job if not in queue yet.
     */
    public function addJobOnce(string $class, $data, array $options = []): Process
    {
        $filter = [
            'class' => $class,
            'data' => $data,
            '$or' => [
                ['status' => JobInterface::STATUS_WAITING],
                ['status' => JobInterface::STATUS_POSTPONED],
            ],
        ];

        $result = $this->db->{$this->job_queue}->findOne($filter, [
            'typeMap' => self::TYPE_MAP,
        ]);

        if (null !== $result && array_intersect_key($result, $options) !== $options) {
            $this->logger->debug('job ['.$result['_id'].'] options changed, reschedule new job', [
                'category' => get_class($this),
                'data' => $data,
            ]);

            $this->cancelJob($result['_id']);
            $result = null;
        }

        if (null === $result) {
            return $this->addJob($class, $data, $options);
        }

        $this->logger->debug('queue job ['.$result['_id'].'] of type ['.$class.'] already exists', [
            'category' => get_class($this),
            'data' => $data,
        ]);

        return new Process($result, $this, $this->events);
    }

    /**
     * Listen for events.
     *
     * @param Closure callback
     */
    public function listen(Closure $callback, array $query = []): void
    {
        $cursor = $this->events->getCursor($query);

        while (true) {
            if (null === $cursor->current()) {
                if ($cursor->getInnerIterator()->isDead()) {
                    $this->logger->error('events queue cursor is dead, is it a capped collection?', [
                        'category' => get_class($this),
                    ]);

                    $this->events->create();

                    $this->wait();

                    break;
                }

                $this->events->next($cursor);

                continue;
            }

            $result = $cursor->current();
            $this->events->next($cursor);
            $process = new Process($result, $this, $this->events);
            $callback->call($process);
        }
    }

    /**
     * Validate given job options.
     *
     *
     * @return Scheduler
     */
    protected function validateOptions(array $options): self
    {
        foreach ($options as $option => $value) {
            switch ($option) {
                case self::OPTION_AT:
                case self::OPTION_INTERVAL:
                case self::OPTION_RETRY:
                case self::OPTION_RETRY_INTERVAL:
                    if (!is_int($value)) {
                        throw new InvalidArgumentException('option '.$option.' must be an integer');
                    }

                break;
                case self::OPTION_IGNORE_MAX_CHILDREN:
                    if (!is_bool($value)) {
                        throw new InvalidArgumentException('option '.$option.' must be a boolean');
                    }

                break;
                default:
                    throw new InvalidArgumentException('invalid option '.$option.' given');
            }
        }

        return $this;
    }

    /**
     * Update job status.
     */
    protected function updateJob(ObjectId $id, int $status): UpdateResult
    {
        $result = $this->db->{$this->job_queue}->updateMany([
            '_id' => $id,
            '$isolated' => true,
        ], [
            '$set' => [
                'status' => $status,
            ],
        ]);

        return $result;
    }
}
