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

use InvalidArgumentException;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Database;
use Psr\Log\LoggerInterface;
use Traversable;

class Scheduler
{
    /**
     * Job options.
     */
    const OPTION_AT = 'at';
    const OPTION_INTERVAL = 'interval';
    const OPTION_RETRY = 'retry';
    const OPTION_RETRY_INTERVAL = 'retry_interval';

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
     * Collection name.
     *
     * @var string
     */
    protected $collection_name = 'queue';

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
    protected $default_retry = 0;

    /**
     * Default retry interval (secconds).
     *
     * @var int
     */
    protected $default_retry_interval = 300;

    /**
     * Queue size.
     *
     * @var int
     */
    protected $queue_size = 100000;

    /**
     * Init queue.
     *
     * @param Database        $db
     * @param LoggerInterface $logger
     * @param iterable        $config
     */
    public function __construct(Database $db, LoggerInterface $logger, ?Iterable $config = null)
    {
        $this->db = $db;
        $this->logger = $logger;

        if (null !== $config) {
            $this->setOptions($config);
        }
    }

    /**
     * Set options.
     *
     * @param iterable $config
     *
     * @return Async
     */
    public function setOptions(Iterable $config = []): self
    {
        foreach ($config as $option => $value) {
            switch ($option) {
                case 'collection_name':
                    $this->{$option} = (string) $value;

                break;
                case 'default_retry':
                case 'default_at':
                case 'default_retry_interval':
                case 'default_interval':
                case 'queue_size':
                    $this->{$option} = (int) $value;

                break;
                default:
                    throw new InvalidArgumentException('invalid option '.$option.' given');
            }
        }

        return $this;
    }

    /**
     * Get collection name.
     *
     * @return string
     */
    public function getCollection(): string
    {
        return $this->collection_name;
    }

    /**
     * Create queue collection.
     *
     * @return Async
     */
    public function createQueue(): self
    {
        $this->db->createCollection(
            $this->collection_name,
            [
                'capped' => true,
                'size' => $this->queue_size,
            ]
        );

        return $this;
    }

    /**
     * Get job by ID.
     *
     * @param ObjectId
     *
     * @return array
     */
    public function getJob(ObjectId $id): array
    {
        $result = $this->db->{$this->collection_name}->findOne([
            '_id' => $id,
        ], [
            'typeMap' => [
                'document' => 'array',
                'root' => 'array',
                'array' => 'array',
            ],
        ]);

        if (null === $result) {
            throw new Exception\JobNotFound('job '.$id.' was not found');
        }

        return $result;
    }

    /**
     * Cancel job.
     *
     * @param ObjectId $id
     *
     * @return bool
     */
    public function cancelJob(ObjectId $id): bool
    {
        return $this->updateJob($id, Queue::STATUS_CANCELED);
    }

    /**
     * Get jobs (Pass a filter which contains job status, by default all active jobs get returned).
     *
     * @param array $filter
     *
     * @return Traversable
     */
    public function getJobs(array $filter = []): Traversable
    {
        if (0 === count($filter)) {
            $filter = [
                Queue::STATUS_WAITING,
                Queue::STATUS_PROCESSING,
                Queue::STATUS_POSTPONED,
            ];
        }

        $result = $this->db->{$this->collection_name}->find([
            'status' => [
                '$in' => $filter,
            ],
        ], [
            'typeMap' => [
                'document' => 'array',
                'root' => 'array',
                'array' => 'array',
            ],
        ]);

        return $result;
    }

    /**
     * Add job to queue.
     *
     * @param string $class
     * @param mixed  $data
     * @param array  $options
     *
     * @return ObjectId
     */
    public function addJob(string $class, $data, array $options = []): ObjectId
    {
        $defaults = [
            self::OPTION_AT => $this->default_at,
            self::OPTION_INTERVAL => $this->default_interval,
            self::OPTION_RETRY => $this->default_retry,
            self::OPTION_RETRY_INTERVAL => $this->default_retry_interval,
        ];

        $options = array_merge($defaults, $options);
        $this->validateOptions($options);
        $at = null;

        if ($options[self::OPTION_AT] > 0) {
            $at = new UTCDateTime($options[self::OPTION_AT] * 1000);
        }

        $result = $this->db->{$this->collection_name}->insertOne([
            'class' => $class,
            'status' => Queue::STATUS_WAITING,
            'timestamp' => new UTCDateTime(),
            'at' => $at,
            'retry' => $options[self::OPTION_RETRY],
            'retry_interval' => $options[self::OPTION_RETRY_INTERVAL],
            'interval' => $options[self::OPTION_INTERVAL],
            'data' => $data,
        ], ['$isolated' => true]);

        $this->logger->debug('queue job ['.$result->getInsertedId().'] added to ['.$class.']', [
            'category' => get_class($this),
            'params' => $options,
            'data' => $data,
        ]);

        return $result->getInsertedId();
    }

    /**
     * Only add job if not in queue yet.
     *
     * @param string $class
     * @param mixed  $data
     * @param array  $options
     *
     * @return ObjectId
     */
    public function addJobOnce(string $class, $data, array $options = []): ObjectId
    {
        $filter = [
            'class' => $class,
            'data' => $data,
            '$or' => [
                ['status' => Queue::STATUS_WAITING],
                ['status' => Queue::STATUS_POSTPONED],
            ],
        ];

        $result = $this->db->queue->findOne($filter);

        if (null === $result) {
            return $this->addJob($class, $data, $options);
        }
        $this->logger->debug('queue job ['.$result['_id'].'] of type ['.$class.'] already exists', [
                'category' => get_class($this),
                'data' => $data,
            ]);

        return $result['_id'];
    }

    /**
     * Validate given job options.
     *
     * @param array $options
     *
     * @return Async
     */
    protected function validateOptions(array $options): self
    {
        if (count($options) > 4) {
            throw new InvalidArgumentException('invalid option given');
        }

        if (4 !== count(array_filter($options, 'is_int'))) {
            throw new InvalidArgumentException('Only integers are allowed to passed');
        }

        return $this;
    }

    /**
     * Update job status.
     *
     * @param ObjectId $id
     * @param int      $status
     *
     * @return bool
     */
    protected function updateJob(ObjectId $id, int $status): bool
    {
        $result = $this->db->{$this->collection_name}->updateMany([
            '_id' => $id,
            '$isolated' => true,
        ], [
            '$set' => [
                'status' => $status,
                'timestamp' => new UTCDateTime(),
            ],
        ]);

        return $result->isAcknowledged();
    }
}
