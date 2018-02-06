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

use IteratorIterator;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Database;
use MongoDB\Driver\Cursor;
use MongoDB\Operation\Find;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class Async
{
    /**
     * Job status.
     */
    const STATUS_WAITING = 0;
    const STATUS_POSTPONED = 1;
    const STATUS_PROCESSING = 2;
    const STATUS_DONE = 3;
    const STATUS_FAILED = 4;

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
     * Local queue.
     *
     * @var array
     */
    protected $queue = [];

    /**
     * Collection name.
     *
     * @var string
     */
    protected $collection_name = 'queue';

    /**
     * Container.
     *
     * @var ContainerInterface
     */
    protected $container;

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
     * @param Database           $db
     * @param LoggerInterface    $logger
     * @param ContainerInterface $container
     * @param iterable           $config
     */
    public function __construct(Database $db, LoggerInterface $logger, ?ContainerInterface $container = null, ?Iterable $config = null)
    {
        $this->db = $db;
        $this->logger = $logger;
        $this->container = $container;
        $this->setOptions($config);
    }

    /**
     * Set options.
     *
     * @param iterable $config
     *
     * @return Async
     */
    public function setOptions(? Iterable $config = null): self
    {
        if (null === $config) {
            return $this;
        }

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
                    throw new Exception('invalid option '.$option.' given');
            }
        }

        return $this;
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
     * Validate given job options.
     *
     * @param array $options
     *
     * @return Async
     */
    public function validateOptions(array $options): self
    {
        foreach ($options as $option => $value) {
            switch ($option) {
                case self::OPTION_AT:
                case self::OPTION_RETRY:
                case self::OPTION_RETRY_INTERVAL:
                case self::OPTION_INTERVAL:
                    if (!is_int($value)) {
                        throw new Exception('option '.$option.' must be an integer');
                    }

                break;
                default:
                    throw new Exception('invalid option '.$option.' given');
            }
        }

        return $this;
    }

    /**
     * Add job to queue.
     *
     * @param string $class
     * @param mixed  $data
     * @param array  $options
     *
     * @return bool
     */
    public function addJob(string $class, $data, array $options = []): bool
    {
        $defaults = [
            self::OPTION_AT => $this->default_at,
            self::OPTION_INTERVAL => $this->default_interval,
            self::OPTION_RETRY => $this->default_retry,
            self::OPTION_RETRY_INTERVAL => $this->default_retry_interval,
        ];

        $options = array_merge($defaults, $options);
        $this->validateOptions($options);

        if ($options[self::OPTION_AT] > 0) {
            $at = new UTCDateTime($options[self::OPTION_AT] * 1000);
        } else {
            $at = null;
        }

        $result = $this->db->{$this->collection_name}->insertOne([
            'class' => $class,
            'status' => self::STATUS_WAITING,
            'timestamp' => new UTCDateTime(),
            'at' => $at,
            'retry' => $options[self::OPTION_RETRY],
            'retry_interval' => $options[self::OPTION_RETRY_INTERVAL],
            'interval' => $options[self::OPTION_INTERVAL],
            'data' => $data,
        ]);

        $this->logger->debug('queue job ['.$result->getInsertedId().'] added to ['.$class.']', [
            'category' => get_class($this),
            'params' => $options,
            'data' => $data,
        ]);

        return $result->isAcknowledged();
    }

    /**
     * Only add job if not in queue yet.
     *
     * @param string $class
     * @param mixed  $data
     * @param array  $options
     *
     * @return bool
     */
    public function addJobOnce(string $class, $data, array $options = []): bool
    {
        $filter = [
            'class' => $class,
            'data' => $data,
            '$or' => [
                ['status' => self::STATUS_WAITING],
                ['status' => self::STATUS_POSTPONED],
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

        return true;
    }

    /**
     * Execute job queue as endless loop.
     *
     * @return bool
     */
    public function startDaemon(): bool
    {
        $cursor = $this->getCursor();

        while (true) {
            $this->processLocalQueue();

            if (null === $cursor->current()) {
                if ($cursor->getInnerIterator()->isDead()) {
                    $this->logger->error('job queue cursor is dead, is it a capped collection?', [
                        'category' => get_class($this),
                    ]);

                    return $this->startDaemon();
                }

                $cursor->next();

                continue;
            }

            $job = $cursor->current();
            $cursor->next();
            $this->processJob($job);
        }
    }

    /**
     * Execute job queue.
     *
     * @return bool
     */
    public function startOnce(): bool
    {
        $cursor = $this->getCursor(false);

        while (true) {
            $this->processLocalQueue();

            if (null === $cursor->current()) {
                if ($cursor->getInnerIterator()->isDead()) {
                    $this->logger->debug('all jobs were processed', [
                        'category' => get_class($this),
                    ]);

                    return false;
                }

                return true;
            }

            $job = $cursor->current();
            $cursor->next();
            $this->processJob($job);
        }
    }

    /**
     * Get cursor.
     *
     * @param bool $tailable
     *
     * @return IteratorIterator
     */
    protected function getCursor(bool $tailable = true): IteratorIterator
    {
        $options = [];
        if (true === $tailable) {
            $options['cursorType'] = Find::TAILABLE;
            $options['noCursorTimeout'] = true;
        }

        $cursor = $this->db->{$this->collection_name}->find([
            '$or' => [
                ['status' => self::STATUS_WAITING],
                ['status' => self::STATUS_POSTPONED,
                 'at' => ['$gte' => new UTCDateTime()], ],
            ],
        ], $options);

        $iterator = new IteratorIterator($cursor);
        $iterator->rewind();

        return $iterator;
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
        $result = $this->db->{$this->collection_name}->updateMany(['_id' => $id, '$isolated' => true], ['$set' => [
            'status' => $status,
            'timestamp' => new UTCDateTime(),
        ]]);

        $this->logger->debug('job ['.$id.'] updated to status ['.$status.']', [
            'category' => get_class($this),
        ]);

        return $result->isAcknowledged();
    }

    /**
     * Check local queue for postponed jobs.
     *
     * @return bool
     */
    protected function processLocalQueue()
    {
        $now = new UTCDateTime();
        foreach ($this->queue as $key => $job) {
            if ($job['at'] <= $now) {
                $this->logger->info('postponed job ['.$job['_id'].'] ['.$job['class'].'] can now be executed', [
                    'category' => get_class($this),
                ]);

                unset($this->queue[$key]);
                $job['at'] = null;

                $this->processJob($job);
            }
        }

        return true;
    }

    /**
     * Process job.
     *
     * @param array $job
     *
     * @return bool
     */
    protected function processJob(array $job): bool
    {
        if ($job['at'] instanceof UTCDateTime) {
            $this->updateJob($job['_id'], self::STATUS_POSTPONED);
            $this->queue[] = $job;

            $this->logger->debug('execution of job ['.$job['_id'].'] ['.$job['class'].'] is postponed at ['.$job['at'].']', [
                'category' => get_class($this),
            ]);

            return true;
        }

        $this->updateJob($job['_id'], self::STATUS_PROCESSING);

        $this->logger->debug('execute job ['.$job['_id'].'] ['.$job['class'].']', [
            'category' => get_class($this),
            'params' => $job['data'],
        ]);

        try {
            $this->executeJob($job);
        } catch (\Exception $e) {
            $this->logger->error('failed execute job ['.$job['_id'].']', [
                'category' => get_class($this),
                'exception' => $e,
            ]);

            $this->updateJob($job['_id'], self::STATUS_FAILED);

            if ($job['retry'] > 0) {
                $this->logger->debug('failed job ['.$job['_id'].'] has a retry interval of ['.$job['retry'].']', [
                    'category' => get_class($this),
                ]);

                $this->addJob($job['class'], $job['data'], [
                    self::OPTION_AT => time() + $job['retry_interval'],
                    self::OPTION_INTERVAL => $job['interval'],
                    self::OPTION_RETRY => --$job['retry'],
                    self::OPTION_RETRY_INTERVAL => $job['retry_interval'],
                ]);
            }
        }

        if ($job['interval'] >= 0) {
            $this->addJob($job['class'], $job['data'], [
                self::OPTION_AT => time() + $job['interval'],
                self::OPTION_INTERVAL => $job['interval'],
                self::OPTION_RETRY => $job['retry'],
                self::OPTION_RETRY_INTERVAL => $job['retry_interval'],
            ]);
        }

        return true;
    }

    /**
     * Execute job.
     *
     * @param array $job
     *
     * @return bool
     */
    protected function executeJob(array $job): bool
    {
        if (!class_exists($job['class'])) {
            throw new Exception('job class does not exists');
        }

        if (null === $this->container) {
            $instance = new $job['class']();
        } else {
            $instance = $this->container->get($job['class']);
        }

        if (!($instance instanceof JobInterface)) {
            throw new Exception('job must implement JobInterface');
        }

        $instance->setData($job['data'])
            ->start();

        return $this->updateJob($job['_id'], self::STATUS_DONE);
    }
}
