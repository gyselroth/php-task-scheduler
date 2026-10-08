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

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Database;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use TaskScheduler\Exception\ChildJobFailure;
use TaskScheduler\Exception\InvalidJobException;
use TaskScheduler\Exception\JobTimeout;

class Worker
{
    use InjectTrait;

    /**
     * Scheduler.
     *
     * @var Scheduler
     */
    protected $scheduler;

    /**
     * Database.
     *
     * @var Database
     */
    protected $db;

    /**
     * Logger.
     *
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * Container.
     *
     * @var ContainerInterface|null
     */
    protected $container;

    /**
     * Local queue.
     *
     * @var array
     */
    protected $queue = [];

    /**
     * Current processing job.
     *
     * @var array|null
     */
    protected $current_job;

    /**
     * Process ID.
     *
     * @var int
     */
    protected $process;

    /**
     * Worker ID.
     *
     * @var ObjectId
     */
    protected $id;

    /**
     * SessionHandler.
     *
     * @var SessionHandler
     */
    protected $sessionHandler;

    /**
     * Init worker.
     */
    public function __construct(
        ObjectId $id,
        Scheduler $scheduler,
        Database $db,
        LoggerInterface $logger,
        ?ContainerInterface $container = null
    ) {
        $this->id = $id;
        $this->process = getmypid();
        $this->scheduler = $scheduler;
        $this->db = $db;
        $this->logger = $logger;
        $this->sessionHandler = new SessionHandler($this->db, $this->logger);
        $this->container = $container;
    }

    /**
     * Handle worker timeout.
     */
    public function timeout(): ?ObjectId
    {
        if (null === $this->current_job) {
            $this->logger->debug(
                'reached worker timeout signal, no job is currently processing, ignore it',
                [
                    'category' => get_class($this),
                    'pm' => $this->process,
                ]
            );

            return null;
        }

        $job = $this->current_job;
        $job['options'] = $this->normalizeJobOptions($job['options']);

        $this->logger->debug(
            'received timeout signal, reschedule current processing job [' . $job['_id'] . ']',
            [
                'category' => get_class($this),
                'pm' => $this->process,
            ]
        );

        $this->updateJob($job, JobInterface::STATUS_TIMEOUT);
        $this->updateChildJobs($job, JobInterface::STATUS_TIMEOUT);

        if ($job['options']['retry'] !== 0) {
            if ($job['options']['retry'] > 0) {
                --$job['options']['retry'];
            }
            $job['options']['at'] = time() + $job['options']['retry_interval'];

            $newJob = $this->scheduler->addJob(
                $job['class'],
                $job['data'],
                $job['options']
            );

            $this->killProcess();

            return $newJob->getId();
        }

        if ($job['options']['interval'] > 0) {
            $job['options']['at'] = time() + $job['options']['interval'];

            $newJob = $this->scheduler->addJob(
                $job['class'],
                $job['data'],
                $job['options']
            );

            $this->killProcess();

            return $newJob->getId();
        }

        if ($job['options']['interval'] <= -1) {
            unset($job['options']['at']);

            $newJob = $this->scheduler->addJob(
                $job['class'],
                $job['data'],
                $job['options']
            );

            $this->killProcess();

            return $newJob->getId();
        }

        $this->killProcess();

        return null;
    }

    /**
     * Start worker and process all jobs.
     */
    public function processAll(): void
    {
        $this->logger->info('start job listener', [
            'category' => get_class($this),
            'pm' => $this->process,
        ]);

        $this->catchSignal();

        $collection = $this->db->selectCollection(
            $this->scheduler->getJobQueue()
        );

        /*
         * IMPORTANT:
         *
         * Open the change stream BEFORE loading existing jobs.
         *
         * Otherwise a job inserted between find() and watch() can be
         * missed completely.
         */
        $cursorWatch = $collection->watch(
            [
                [
                    '$match' => [
                        'fullDocument.options.force_spawn' => false,
                        'fullDocument.worker' => null,
                        '$or' => [
                            [
                                'fullDocument.status' =>
                                    JobInterface::STATUS_WAITING,
                            ],
                            [
                                'fullDocument.status' =>
                                    JobInterface::STATUS_POSTPONED,
                            ],
                        ],
                    ],
                ],
            ],
            [
                'fullDocument' => 'updateLookup',
            ]
        );

        /*
         * Load jobs which already existed before the worker started.
         *
         * Duplicates between this query and the change stream are harmless
         * because collectJob() performs an atomic status/worker check.
         */
        $jobs = $collection->find(
            [
                'worker' => null,
                '$or' => [
                    [
                        'status' => JobInterface::STATUS_WAITING,
                    ],
                    [
                        'status' => JobInterface::STATUS_POSTPONED,
                    ],
                ],
            ],
            [
                'limit' => 300,
                'typeMap' => Scheduler::TYPE_MAP,
            ]
        )->toArray();

        foreach ($jobs as $job) {
            $this->queueJob((array) $job);
        }

        while ($this->loop()) {
            $this->processLocalQueue();

            try {
                if ($cursorWatch->valid()) {
                    $change = $cursorWatch->current();

                    if (null !== $change) {
                        $change = (array) $change;

                        if (
                            isset($change['fullDocument'])
                            && null !== $change['fullDocument']
                        ) {
                            $this->queueJob(
                                (array) $change['fullDocument']
                            );
                        }
                    }

                    $cursorWatch->next();
                } else {
                    /*
                     * Change streams are tailable/awaitable cursors.
                     * Calling next() allows the driver to wait for new data.
                     */
                    $cursorWatch->next();
                }
            } catch (\Throwable $e) {
                $this->logger->error(
                    'failed processing MongoDB change stream',
                    [
                        'category' => get_class($this),
                        'pm' => $this->process,
                        'exception' => $e,
                    ]
                );

                usleep(100000);
            }
        }
    }

    /**
     * Process exactly one job.
     */
    public function processOne(ObjectId $id): void
    {
        $this->catchSignal();

        $this->logger->debug(
            'process job [' . $id . '] and exit',
            [
                'category' => get_class($this),
                'pm' => $this->process,
            ]
        );

        try {
            $job = $this->scheduler->getJob($id)->toArray();

            // processOne() is a single-pass worker: future jobs are marked
            // postponed by processJob() instead of blocking this call.
            if (
                in_array(
                    (int) $job['status'],
                    JobInterface::FAILED_JOBS,
                    true
                )
            ) {
                return;
            }

            $this->queueJob($job);
        } catch (\Throwable $e) {
            $this->logger->error(
                'failed process job [' . $id . ']',
                [
                    'category' => get_class($this),
                    'pm' => $this->process,
                    'exception' => $e,
                ]
            );
        }
    }

    /**
     * Cleanup and exit.
     */
    public function cleanup(): ?ObjectId
    {
        $this->saveState();

        if (null === $this->current_job) {
            $this->logger->debug(
                'received cleanup call on worker [' . $this->id . '], no job is currently processing, exit now',
                [
                    'category' => get_class($this),
                    'pm' => $this->process,
                ]
            );

            $this->exit();

            return null;
        }

        $job = $this->current_job;
        $job['options'] = $this->normalizeJobOptions($job['options']);

        $this->logger->debug(
            'received cleanup call on worker [' . $this->id . '], reschedule current processing job [' . $job['_id'] . ']',
            [
                'category' => get_class($this),
                'pm' => $this->process,
            ]
        );

        $this->updateJob($job, JobInterface::STATUS_CANCELED);
        $this->updateChildJobs($job, JobInterface::STATUS_CANCELED);

        $options = $job['options'];
        $options['at'] = 0;

        $newJob = $this->scheduler->addJob(
            $job['class'],
            $job['data'],
            $options
        );

        $this->exit();

        return $newJob->getId();
    }

    /**
     * Save local queue.
     */
    protected function saveState(): self
    {
        if (empty($this->queue)) {
            return $this;
        }

        $session = $this->sessionHandler->getSession();
        $session->startTransaction(
            $this->sessionHandler->getOptions()
        );

        try {
            foreach ($this->queue as $job) {
                $this->db
                    ->selectCollection($this->scheduler->getJobQueue())
                    ->updateOne(
                        ['_id' => $job['_id']],
                        ['$setOnInsert' => $job],
                        ['upsert' => true, 'session' => $session]
                    );
            }

            $session->commitTransaction();
        } catch (\Throwable $e) {
            try {
                $session->abortTransaction();
            } catch (\Throwable $ignored) {
            }

            $this->logger->error(
                'failed to save local worker queue',
                [
                    'category' => get_class($this),
                    'pm' => $this->process,
                    'exception' => $e,
                ]
            );
        }

        return $this;
    }

    /**
     * Catch signals.
     */
    protected function catchSignal(): self
    {
        pcntl_async_signals(true);

        pcntl_signal(SIGTERM, [$this, 'cleanup']);
        pcntl_signal(SIGINT, [$this, 'cleanup']);
        pcntl_signal(SIGALRM, [$this, 'timeout']);

        return $this;
    }

    /**
     * Queue job.
     */
    protected function queueJob(array $job): bool
    {
        if (
            !isset($job['_id'], $job['status'])
            || !isset($job['options'])
        ) {
            return false;
        }

        /*
         * Do not execute a child job when its parent has already failed.
         */
        if (isset($job['data']['parent'])) {
            try {
                $parentJob = $this->scheduler
                    ->getJob($job['data']['parent'])
                    ->toArray();

                if (
                    in_array(
                        (int) $parentJob['status'],
                        JobInterface::FAILED_JOBS,
                        true
                    )
                ) {
                    $this->logger->debug(
                        'parent job [' . $parentJob['_id'] . '] not running anymore. do not queue child job [' . $job['_id'] . ']',
                        [
                            'category' => get_class($this),
                            'pm' => $this->process,
                        ]
                    );

                    $this->db
                        ->{$this->scheduler->getJobQueue()}
                        ->updateOne(
                            [
                                '_id' => $job['_id'],
                                'status' => [
                                    '$in' => JobInterface::PENDING_JOBS,
                                ],
                            ],
                            [
                                '$set' => [
                                    'status' =>
                                        JobInterface::STATUS_CANCELED,
                                    'ended' => new UTCDateTime(),
                                ],
                            ]
                        );

                    return false;
                }
            } catch (\Throwable $e) {
                $this->logger->error(
                    'failed to check parent job for job [' . $job['_id'] . ']',
                    [
                        'category' => get_class($this),
                        'pm' => $this->process,
                        'exception' => $e,
                    ]
                );

                return false;
            }
        }

        $this->logger->debug(
            'queue job [' . $job['_id'] . '] in queue with status [' . $job['status'] . ']',
            [
                'category' => get_class($this),
                'pm' => $this->process,
            ]
        );

        if (
            true === $this->collectJob(
                $job,
                JobInterface::STATUS_PROCESSING
            )
        ) {
            $this->scheduler->emitEvent(
                $this->scheduler->getJob($job['_id'])
            );

            $this->processJob($job);

            /*
             * processJob() may have changed the job considerably.
             * Always re-read it before emitting the final event.
             */
            try {
                $this->scheduler->emitEvent(
                    $this->scheduler->getJob($job['_id'])
                );
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'failed to emit final event for job [' . $job['_id'] . ']',
                    [
                        'category' => get_class($this),
                        'pm' => $this->process,
                        'exception' => $e,
                    ]
                );
            }

            return true;
        }

        if (
            JobInterface::STATUS_POSTPONED === (int) $job['status']
        ) {
            try {
                $this->scheduler->emitEvent(
                    $this->scheduler->getJob($job['_id'])
                );
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'failed to emit postponed event for job [' . $job['_id'] . ']',
                    [
                        'category' => get_class($this),
                        'pm' => $this->process,
                        'exception' => $e,
                    ]
                );
            }

            $queueKey = (string) $job['_id'];
            // Preserve an existing local snapshot. In particular, tests and
            // the worker wake-up path may update the local due time while the
            // persisted document still contains its original future timestamp.
            if (!isset($this->queue[$queueKey])) {
                $this->queue[$queueKey] = $job;
            }
        }

        return true;
    }

    /**
     * Collect job atomically.
     */
    protected function collectJob(
        array $job,
        int $status,
        int $from_status = JobInterface::STATUS_WAITING
    ): bool {
        $this->logger->debug(
            'try to collect job [' . $job['_id'] . '] with status [' . $from_status . '] by worker [' . $this->id . ']',
            [
                'category' => get_class($this),
                'pm' => $this->process,
            ]
        );

        $set = [
            'status' => $status,
        ];

        if (JobInterface::STATUS_PROCESSING === $status) {
            $timestamp = new UTCDateTime();

            $set['started'] = $timestamp;
            $set['alive'] = $timestamp;
            $set['worker'] = $this->id;
        }

        $session = $this->sessionHandler->getSession();
        $session->startTransaction(
            $this->sessionHandler->getOptions()
        );

        try {
            $result = $this->db
                ->{$this->scheduler->getJobQueue()}
                ->updateOne(
                    [
                        '_id' => $job['_id'],
                        'status' => $from_status,
                        '$or' => [
                            ['worker' => null],
                            ['worker' => ['$exists' => false]],
                            [
                                'worker' => $this->id,
                            ],
                        ],
                    ],
                    [
                        '$set' => $set,
                    ],
                    ['session' => $session]
                );

            $session->commitTransaction();
        } catch (\Throwable $e) {
            try {
                $session->abortTransaction();
            } catch (\Throwable $ignored) {
            }

            $this->logger->error(
                'failed to collect job [' . $job['_id'] . ']',
                [
                    'category' => get_class($this),
                    'pm' => $this->process,
                    'exception' => $e,
                ]
            );

            return false;
        }

        return 1 === $result->getModifiedCount();
    }

    /**
     * Update job status.
     */
    protected function updateJob(array $job, int $status): bool
    {
        $set = [
            'status' => $status,
        ];

        if ($status >= JobInterface::STATUS_DONE) {
            $set['ended'] = new UTCDateTime();

            if (isset($job['progress'])) {
                $set['progress'] = 100.0;
            }
        }

        /*
         * Restrict changes to non-terminal jobs. Jobs passed to this method
         * can be stale local queue snapshots (for example, still marked as
         * WAITING even though collectJob() has claimed them in MongoDB).
         * Permit unassigned jobs for explicit lifecycle operations, but do
         * not let this worker alter a job owned by a different worker.
         */
        $filter = [
            '_id' => $job['_id'],
            'status' => ['$in' => JobInterface::PENDING_JOBS],
            '$or' => [
                ['worker' => $this->id],
                ['worker' => null],
                ['worker' => ['$exists' => false]],
            ],
        ];

        $session = $this->sessionHandler->getSession();
        $session->startTransaction(
            $this->sessionHandler->getOptions()
        );

        try {
            $result = $this->db
                ->{$this->scheduler->getJobQueue()}
                ->updateOne(
                    $filter,
                    ['$set' => $set],
                    ['session' => $session]
                );

            $session->commitTransaction();
        } catch (\Throwable $e) {
            try {
                $session->abortTransaction();
            } catch (\Throwable $ignored) {
            }

            $this->logger->error(
                'failed to update job [' . $job['_id'] . ']',
                [
                    'category' => get_class($this),
                    'pm' => $this->process,
                    'exception' => $e,
                ]
            );

            return false;
        }

        if (0 === $result->getModifiedCount()) {
            $this->logger->warning(
                'job [' . $job['_id'] . '] was not updated because it is no longer owned by worker [' . $this->id . ']',
                [
                    'category' => get_class($this),
                    'pm' => $this->process,
                ]
            );

            return false;
        }

        /*
         * Notifications happen AFTER the database transaction.
         *
         * An external notification cannot be rolled back together with
         * MongoDB, so it must not execute inside the transaction.
         */
        $this->notifyJob($job, $status);

        return true;
    }

    /**
     * Send job notification.
     */
    protected function notifyJob(array $job, int $status): void
    {
        if (null === $this->container) {
            return;
        }

        try {
            $instance = $this->container->get($job['class']);

            if (!method_exists($instance, 'notification')) {
                return;
            }

            $liveJob = $this->scheduler
                ->getJob($job['_id'])
                ->toArray();

            if (isset($liveJob['notification_sent'])) {
                return;
            }

            $instance->notification($status, $liveJob);

            $this->db
                ->{$this->scheduler->getJobQueue()}
                ->updateOne(
                    [
                        '_id' => $job['_id'],
                        'notification_sent' => [
                            '$exists' => false,
                        ],
                    ],
                    [
                        '$set' => [
                            'notification_sent' => true,
                        ],
                    ]
                );
        } catch (\Throwable $e) {
            $this->logger->error(
                'failed to send notification for job [' . $job['_id'] . ']',
                [
                    'category' => get_class($this),
                    'pm' => $this->process,
                    'exception' => $e,
                ]
            );
        }
    }

    /**
     * Update child jobs.
     */
    protected function updateChildJobs(array $job, int $status): bool
    {
        /*
         * Do not touch jobs which are already terminal.
         */
        $filter = [
            'data.parent' => $job['_id'],
            'status' => [
                '$in' => [
                    JobInterface::STATUS_WAITING,
                    JobInterface::STATUS_POSTPONED,
                    JobInterface::STATUS_PROCESSING,
                ],
            ],
        ];

        $set = [
            'status' => $status,
            'ended' => new UTCDateTime(),
        ];

        $session = $this->sessionHandler->getSession();
        $session->startTransaction(
            $this->sessionHandler->getOptions()
        );

        try {
            $result = $this->db
                ->{$this->scheduler->getJobQueue()}
                ->updateMany(
                    $filter,
                    ['$set' => $set],
                    ['session' => $session]
                );

            $session->commitTransaction();
        } catch (\Throwable $e) {
            try {
                $session->abortTransaction();
            } catch (\Throwable $ignored) {
            }

            $this->logger->error(
                'failed to update child jobs for parent [' . $job['_id'] . ']',
                [
                    'category' => get_class($this),
                    'pm' => $this->process,
                    'exception' => $e,
                ]
            );

            return false;
        }

        return $result->isAcknowledged();
    }

    /**
     * Check local queue for postponed jobs.
     */
    protected function processLocalQueue(): bool
    {
        if (empty($this->queue)) {
            return true;
        }

        $now = time();

        foreach ($this->queue as $key => $job) {
            try {
                if (
                    !isset($job['options']['at'])
                    || $job['options']['at'] > $now
                ) {
                    continue;
                }

                /*
                 * Only move a postponed job back to waiting while its status
                 * is still POSTPONED. The status predicate is the atomic guard:
                 * a worker claiming the job changes that status to PROCESSING.
                 */
                $result = $this->db
                    ->{$this->scheduler->getJobQueue()}
                    ->updateOne(
                        [
                            '_id' => $job['_id'],
                            'status' => JobInterface::STATUS_POSTPONED,
                        ],
                        [
                            '$set' => [
                                'status' =>
                                    JobInterface::STATUS_WAITING,
                                'worker' => null,
                            ],
                        ]
                    );

                unset($this->queue[$key]);

                if (1 === $result->getModifiedCount()) {
                    $this->logger->info(
                        'set job status of job [' . $job['_id'] . '] to waiting',
                        [
                            'category' => get_class($this),
                            'pm' => $this->process,
                        ]
                    );

                    /*
                     * In processAll(), the MongoDB change stream will pick
                     * this up again.
                     *
                     * For processOne(), there is no change stream, therefore
                     * execute the job directly.
                     */
                    if (
                        !isset($job['options']['force_spawn'])
                        || false === $job['options']['force_spawn']
                    ) {
                        // Keep the local queue's due timestamp. The database
                        // options may still contain the original future time
                        // (for example after a local queue wake-up), and
                        // re-reading them would postpone the job again.
                        $job['status'] = JobInterface::STATUS_WAITING;
                        $job['worker'] = null;
                        $this->queueJob($job);
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->error(
                    'failed to process postponed job [' . $job['_id'] . ']',
                    [
                        'category' => get_class($this),
                        'pm' => $this->process,
                        'exception' => $e,
                    ]
                );
            }
        }

        return true;
    }

    /**
     * Normalize MongoDB/mock document options to a plain PHP array.
     *
     * Some MongoDB implementations return options as traversable documents,
     * while Scheduler::addJob() requires an array.
     */
    protected function normalizeJobOptions($options): array
    {
        if (is_array($options)) {
            return $options;
        }

        if ($options instanceof \Traversable) {
            return iterator_to_array($options);
        }

        if (is_object($options)) {
            return get_object_vars($options);
        }

        throw new \UnexpectedValueException(
            'job options must be an array or document'
        );
    }

    /**
     * Process job.
     */
    protected function processJob(array $job): ObjectId
    {
        $job['options'] = $this->normalizeJobOptions($job['options']);
        $now = time();
        $jobStartTime = $now;

        if ($job['options']['at'] > $now) {
            $this->updateJob(
                $job,
                JobInterface::STATUS_POSTPONED
            );

            $job['status'] = JobInterface::STATUS_POSTPONED;

            $this->queue[(string) $job['_id']] = $job;

            $this->logger->debug(
                'execution of job [' . $job['_id'] . '] [' . $job['class'] . '] is postponed at [' . $job['options']['at'] . ']',
                [
                    'category' => get_class($this),
                    'pm' => $this->process,
                ]
            );

            $this->removeWorker($job);

            return $job['_id'];
        }

        $this->logger->debug(
            'execute job [' . $job['_id'] . '] [' . $job['class'] . '] on worker [' . $this->id . ']',
            [
                'category' => get_class($this),
                'pm' => $this->process,
                'options' => $job['options'],
                'params' => $job['data'],
            ]
        );

        $this->current_job = $job;

        if ($job['options']['timeout'] > 0) {
            pcntl_alarm($job['options']['timeout']);
        }

        try {
            $this->executeJob($job);
            $this->current_job = null;
        } catch (JobTimeout $e) {
            pcntl_alarm(0);

            return $job['_id'];
        } catch (\Throwable $e) {
            pcntl_alarm(0);

            $this->logger->error(
                'failed execute job [' . $job['_id'] . '] of type [' . $job['class'] . '] on worker [' . $this->id . ']',
                [
                    'category' => get_class($this),
                    'pm' => $this->process,
                    'exception' => $e,
                ]
            );

            $this->updateJob(
                $job,
                JobInterface::STATUS_FAILED
            );

            $this->updateChildJobs(
                $job,
                JobInterface::STATUS_FAILED
            );

            $this->current_job = null;

            if ($job['options']['retry'] !== 0) {
                if ($job['options']['retry'] > 0) {
                    --$job['options']['retry'];
                }

                $job['options']['at'] =
                    time() + $job['options']['retry_interval'];

                $newJob = $this->scheduler->addJob(
                    $job['class'],
                    $job['data'],
                    $job['options']
                );

                return $newJob->getId();
            }

            return $job['_id'];
        }

        pcntl_alarm(0);

        if ($job['options']['interval'] > 0) {
            $intervalReference =
                (
                    !isset($job['options']['interval_reference'])
                    || 'end' === $job['options']['interval_reference']
                )
                    ? time()
                    : $jobStartTime;

            $job['options']['at'] =
                $intervalReference + $job['options']['interval'];

            $newJob = $this->scheduler->addJob(
                $job['class'],
                $job['data'],
                $job['options']
            );

            return $newJob->getId();
        }

        if ($job['options']['interval'] <= -1) {
            unset($job['options']['at']);

            $newJob = $this->scheduler->addJob(
                $job['class'],
                $job['data'],
                $job['options']
            );

            return $newJob->getId();
        }

        return $job['_id'];
    }

    /**
     * Execute job.
     */
    protected function executeJob(array $job): bool
    {
        if (!class_exists($job['class'])) {
            throw new InvalidJobException(
                'job class [' . $job['class'] . '] does not exist'
            );
        }

        if (null === $this->container) {
            $instance = new $job['class']();
        } else {
            $instance = $this->container->get($job['class']);
        }

        if (!($instance instanceof JobInterface)) {
            throw new InvalidJobException(
                'job must implement JobInterface'
            );
        }

        $result = $instance
            ->setData($job['data'])
            ->setId($job['_id'])
            ->setScheduler($this->scheduler)
            ->start();

        unset($instance);

        /*
         * The interface explicitly declares start(): bool.
         * A false return value must not silently become DONE.
         */
        if (false === $result) {
            throw new InvalidJobException(
                'job [' . $job['class'] . '] returned false from start()'
            );
        }

        $this->checkChildJobs($job['_id']);

        return $this->updateJob(
            $job,
            JobInterface::STATUS_DONE
        );
    }

    /**
     * Kill process.
     */
    protected function killProcess(): void
    {
        $this->current_job = null;

        posix_kill(
            $this->process,
            SIGTERM
        );
    }

    /**
     * Check child jobs.
     */
    protected function checkChildJobs(ObjectId $jobId): void
    {
        foreach ($this->scheduler->getChildProcs($jobId) as $proc) {
            if (
                in_array(
                    $proc->getStatus(),
                    JobInterface::FAILED_JOBS,
                    true
                )
            ) {
                $this->logger->info(
                    'child job with id [' . $proc->getId() . '] failed',
                    [
                        'category' => get_class($this),
                        'pm' => $this->process,
                    ]
                );

                if (
                    JobInterface::STATUS_TIMEOUT === $proc->getStatus()
                ) {
                    throw new JobTimeout(
                        'child job timed out'
                    );
                }

                throw new ChildJobFailure(
                    'child job failed or was canceled'
                );
            }
        }
    }

    /**
     * Remove worker from job.
     */
    protected function removeWorker(array $job): void
    {
        $session = $this->sessionHandler->getSession();
        $session->startTransaction(
            $this->sessionHandler->getOptions()
        );

        try {
            $result = $this->db
                ->{$this->scheduler->getJobQueue()}
                ->updateOne(
                    [
                        '_id' => $job['_id'],
                        'worker' => $this->id,
                        'status' => JobInterface::STATUS_POSTPONED,
                    ],
                    [
                        '$set' => [
                            'worker' => null,
                        ],
                    ],
                    ['session' => $session]
                );

            $session->commitTransaction();
        } catch (\Throwable $e) {
            try {
                $session->abortTransaction();
            } catch (\Throwable $ignored) {
            }

            $this->logger->error(
                'failed to remove worker from job [' . $job['_id'] . ']',
                [
                    'category' => get_class($this),
                    'pm' => $this->process,
                    'exception' => $e,
                ]
            );

            return;
        }

        if ($result->getModifiedCount() >= 1) {
            $this->logger->debug(
                'removed worker of job [' . $job['_id'] . ']',
                [
                    'category' => get_class($this),
                    'pm' => $this->process,
                ]
            );
        }
    }
}
