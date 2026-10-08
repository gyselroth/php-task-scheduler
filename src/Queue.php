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

use League\Event\Emitter;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Database;
use MongoDB\UpdateResult;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use TaskScheduler\Exception\InvalidArgumentException;
use TaskScheduler\Exception\SpawnForkException;

class Queue
{
    use InjectTrait;
    use EventsTrait;

    public const OPTION_ORPHANED_TIMEOUT = 'orphaned_timeout';
    public const OPTION_ENDLESS_WORKER_TIMEOUT = 'endless_worker_timeout';
    public const OPTION_WAITING_JOBS_FOR_ENDLESS_WORKER = 'waiting_jobs_for_endless_worker';
    public const OPTION_WAITING_TIME_FOR_ENDLESS_WORKER = 'waiting_time_for_endless_worker';

    protected $db;

    protected $logger;

    protected $container;

    protected $factory;

    protected $manager_pid = null;

    protected $queue;

    protected $scheduler;

    protected $orphaned_timeout = 30;

    protected $endless_worker_timeout = 600;

    protected $waiting_jobs_for_endless_worker = 5;

    protected $waiting_time_for_endless_worker = 900;

    protected $waiting_jobs_without_processing = false;

    protected $waiting_jobs = [];

    public function __construct(
        Scheduler $scheduler,
        Database $db,
        WorkerFactoryInterface $factory,
        LoggerInterface $logger,
        ?Emitter $emitter = null,
        array $config = [],
        ?ContainerInterface $container = null
    ) {
        $this->scheduler = $scheduler;
        $this->db = $db;
        $this->logger = $logger;
        $this->factory = $factory;
        $this->emitter = $emitter ?? new Emitter();
        $this->setOptions($config);
        $this->container = $container;
    }

    public function setOptions(array $config = []): self
    {
        foreach ($config as $option => $value) {
            switch ($option) {
                case self::OPTION_ORPHANED_TIMEOUT:
                case self::OPTION_ENDLESS_WORKER_TIMEOUT:
                case self::OPTION_WAITING_JOBS_FOR_ENDLESS_WORKER:
                case self::OPTION_WAITING_TIME_FOR_ENDLESS_WORKER:
                    if (!is_int($value) || $value < 0) {
                        throw new InvalidArgumentException(
                            $option . ' needs to be a non-negative integer'
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

        return $this;
    }

    public function process(): void
    {
        try {
            $key = ftok(__FILE__, 't');

            if ($key === -1) {
                throw new SpawnForkException(
                    'failed to create System V message queue key'
                );
            }

            $this->queue = msg_get_queue($key);

            if ($this->queue === false) {
                throw new SpawnForkException(
                    'failed to create System V message queue'
                );
            }

            $this->catchSignal();
            $this->initWorkerManager();
            $this->main();
        } catch (\Throwable $e) {
            $this->logger->error(
                'main() threw an exception, cleanup and exit',
                [
                    'category' => get_class($this),
                    'exception' => $e,
                ]
            );

            $this->cleanup(SIGTERM);
        }
    }

    public function exitWorkerManager(int $sig, array $pid): void
    {
        $managerPid = isset($pid['pid'])
            ? (int) $pid['pid']
            : 0;

        $this->logger->debug(
            'fork manager [' . $managerPid . '] exit with [' . $sig . ']',
            ['category' => get_class($this)]
        );

        if ($managerPid > 0) {
            pcntl_waitpid($managerPid, $status, WNOHANG | WUNTRACED);
        }

        $this->manager_pid = null;

        $this->cleanup(SIGTERM);
    }

    public function cleanup(int $sig): void
    {
        if ($this->manager_pid !== null && $this->manager_pid > 0) {
            $managerPid = $this->manager_pid;
            $this->manager_pid = null;

            $this->logger->debug(
                'received exit signal [' . $sig . '], forward signal to worker manager',
                ['category' => get_class($this)]
            );

            @posix_kill($managerPid, $sig);
        }

        $this->exit();
    }

    protected function initWorkerManager(): void
    {
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new SpawnForkException(
                'failed to spawn fork manager'
            );
        }

        if ($pid === 0) {
            try {
                $manager = $this->factory->buildManager();
                $manager->process();
            } catch (\Throwable $e) {
                $this->logger->error(
                    'worker manager crashed',
                    [
                        'category' => get_class($this),
                        'exception' => $e,
                    ]
                );

                exit(1);
            }

            exit(0);
        }

        $this->manager_pid = $pid;
    }

    protected function fetchEvents(): void
    {
        while (true) {
            $received = msg_receive(
                $this->queue,
                0,
                $type,
                16384,
                $msg,
                true,
                MSG_IPC_NOWAIT | MSG_NOERROR
            );

            if (!$received) {
                return;
            }

            $this->logger->debug(
                'received systemv message type [' . $type . ']',
                ['category' => get_class($this)]
            );

            switch ($type) {
                case WorkerManager::TYPE_JOB:
                    break;

                case WorkerManager::TYPE_WORKER_SPAWN:
                    if (isset($msg['_id'])) {
                        $this->emitter->emit(
                            'taskscheduler.onWorkerSpawn',
                            $msg['_id']
                        );
                    }

                    break;

                case WorkerManager::TYPE_WORKER_KILL:
                    if (isset($msg['_id'])) {
                        $this->emitter->emit(
                            'taskscheduler.onWorkerKill',
                            $msg['_id']
                        );
                    }

                    break;

                default:
                    $this->logger->warning(
                        'received unknown systemv message type [' . $type . ']',
                        ['category' => get_class($this)]
                    );
            }
        }
    }

    protected function main(): void
    {
        $this->logger->info(
            'start job listener',
            ['category' => get_class($this)]
        );

        $this->catchSignal();

        $collection = $this->db->{$this->scheduler->getJobQueue()};

        $cursorWatch = $collection->watch(
            [],
            [
                'fullDocument' => 'updateLookup',
                'maxAwaitTimeMS' => 1000,
            ]
        );

        $cursorFetch = $collection->find([
            '$or' => [
                ['status' => JobInterface::STATUS_WAITING],
                ['status' => JobInterface::STATUS_POSTPONED],
            ],
        ]);

        foreach ($cursorFetch as $job) {
            $this->fetchEvents();
            $this->handleJob((array) $job);
        }

        $startOrphanCheck = time();
        $startWorkerCheck = time();

        $cursorWatch->rewind();

        while ($this->loop()) {
            $cursorWatch->next();

            if ($cursorWatch->valid()) {
                $event = $cursorWatch->current();

                if ($event !== null && isset($event['fullDocument'])) {
                    $this->fetchEvents();
                    $this->handleJob((array) $event['fullDocument']);
                }
            }

            $now = time();

            if ($now - $startOrphanCheck >= $this->orphaned_timeout) {
                $this->rescheduleOrphanedJobs();
                $startOrphanCheck = $now;
            }

            if ($now - $startWorkerCheck >= $this->endless_worker_timeout) {
                $this->checkEndlessRunningWorkers();
                $startWorkerCheck = $now;
            }

            $this->fetchEvents();
        }
    }

    protected function rescheduleOrphanedJobs(): self
    {
        $this->logger->debug(
            'looking for orphaned jobs',
            ['category' => get_class($this)]
        );

        $aliveUtcDatetime = new UTCDateTime(
            (time() - $this->orphaned_timeout) * 1000
        );

        foreach ($this->scheduler->getOrphanedProcs($aliveUtcDatetime) as $orphanedProc) {
            $hasChildProcs = false;

            foreach ($this->scheduler->getChildProcs($orphanedProc->getId()) as $childProc) {
                $hasChildProcs = true;
                break;
            }

            if ($hasChildProcs) {
                $result = $this->db->{$this->scheduler->getJobQueue()}->updateMany(
                    [
                        'status' => JobInterface::STATUS_PROCESSING,
                        'alive' => ['$lt' => $aliveUtcDatetime],
                        'data.parent' => $orphanedProc->getId(),
                    ],
                    [
                        '$set' => [
                            'status' => JobInterface::STATUS_FAILED,
                            'ended' => new UTCDateTime(),
                        ],
                    ]
                );

                $this->logger->warning(
                    'found [{jobs}] orphaned child jobs, set state to failed',
                    [
                        'category' => get_class($this),
                        'jobs' => $result->getMatchedCount(),
                    ]
                );

                if ($result->getMatchedCount() === 0) {
                    $this->db->{$this->scheduler->getJobQueue()}->updateOne(
                        [
                            '_id' => $orphanedProc->getId(),
                            'status' => JobInterface::STATUS_PROCESSING,
                        ],
                        [
                            '$set' => [
                                'status' => JobInterface::STATUS_DONE,
                                'ended' => new UTCDateTime(),
                            ],
                        ]
                    );

                    $this->sendOrphanedJobEvent($orphanedProc);
                } else {
                    $this->failJobAndNotifyJobClass($orphanedProc);
                }

                continue;
            }

            $this->failJobAndNotifyJobClass($orphanedProc);
        }

        return $this;
    }

    protected function sendOrphanedJobEvent(Process $job): void
    {
        if ($this->queue === null) {
            return;
        }

        @msg_send(
            $this->queue,
            WorkerManager::TYPE_WORKER_ORPHANED_JOB,
            $job->toArray()
        );
    }

    protected function failJobAndNotifyJobClass(Process $job): UpdateResult
    {
        $jobId = $job->getId();

        $result = $this->db->{$this->scheduler->getJobQueue()}->updateOne(
            [
                '_id' => $jobId,
                'status' => [
                    '$in' => [
                        JobInterface::STATUS_PROCESSING,
                        JobInterface::STATUS_WAITING,
                    ],
                ],
            ],
            [
                '$set' => [
                    'status' => JobInterface::STATUS_FAILED,
                    'ended' => new UTCDateTime(),
                ],
            ]
        );

        if ($result->getMatchedCount() !== 1 || $this->container === null) {
            return $result;
        }

        try {
            $instance = $this->container->get($job->getClass());
        } catch (\Throwable $e) {
            $this->logger->error(
                'could not resolve job class for notification',
                [
                    'category' => get_class($this),
                    'class' => $job->getClass(),
                    'exception' => $e,
                ]
            );

            return $result;
        }

        if (!method_exists($instance, 'notification')) {
            $this->logger->info(
                'method notification() does not exist on instance',
                ['category' => get_class($this)]
            );

            return $result;
        }

        $currentJob = $this->scheduler->getJob($jobId)->toArray();

        if (isset($currentJob['notification_sent'])) {
            return $result;
        }

        try {
            $instance->notification(
                JobInterface::STATUS_FAILED,
                $currentJob
            );

            $this->db->{$this->scheduler->getJobQueue()}->updateOne(
                ['_id' => $jobId],
                ['$set' => ['notification_sent' => true]]
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'job failure notification failed',
                [
                    'category' => get_class($this),
                    'job' => (string) $jobId,
                    'exception' => $e,
                ]
            );
        }

        return $result;
    }

    protected function checkEndlessRunningWorkers(): self
    {
        $this->logger->debug(
            'looking for endless running workers',
            ['category' => get_class($this)]
        );

        $collection = $this->db->{$this->scheduler->getJobQueue()};

        $waitingJobs = $collection->find([
            'status' => JobInterface::STATUS_WAITING,
        ])->toArray();

        $processingJobs = $collection->find([
            'status' => JobInterface::STATUS_PROCESSING,
        ])->toArray();

        $numberWaiting = count($waitingJobs);
        $numberProcessing = count($processingJobs);

        $this->logger->debug(
            'found [{jobs_waiting}] waiting jobs and [{jobs_processing}] processing jobs',
            [
                'category' => get_class($this),
                'jobs_waiting' => $numberWaiting,
                'jobs_processing' => $numberProcessing,
            ]
        );

        if ($numberWaiting > $this->waiting_jobs_for_endless_worker && $numberProcessing === 0) {
            $this->endWaitingJobsAndEndWorkerManager();

            return $this;
        }

        if ($numberWaiting === 0 || $numberProcessing > 0) {
            $this->waiting_jobs_without_processing = false;
            $this->waiting_jobs = [];

            return $this;
        }

        $timedOutJobIds = [];

        foreach ($waitingJobs as $job) {
            if (
                !isset($job['started'])
                || $job['started'] === null
            ) {
                continue;
            }

            if (!method_exists($job['started'], 'toDateTime')) {
                continue;
            }

            $started = $job['started']->toDateTime()->getTimestamp();

            if (time() - $started <= $this->waiting_time_for_endless_worker) {
                continue;
            }

            $timedOutJobIds[] = (string) $job['_id'];
        }

        if (count($timedOutJobIds) === 0) {
            return $this;
        }

        if ($this->waiting_jobs_without_processing) {
            foreach ($timedOutJobIds as $jobId) {
                if (in_array($jobId, $this->waiting_jobs, true)) {
                    $this->logger->warning(
                        'found same waiting job with id [' . $jobId . '] after [' .
                        $this->waiting_time_for_endless_worker .
                        's] without processing jobs. exit WorkerManager.',
                        ['category' => get_class($this)]
                    );

                    $this->endWaitingJobsAndEndWorkerManager();

                    return $this;
                }
            }
        }

        $this->waiting_jobs = $timedOutJobIds;
        $this->waiting_jobs_without_processing = true;

        $this->logger->warning(
            'found waiting jobs without processing jobs. check again after [' .
            $this->endless_worker_timeout . ']s',
            ['category' => get_class($this)]
        );

        return $this;
    }

    protected function handleJob(array $job): self
    {
        if (!isset($job['_id'], $job['status'])) {
            return $this;
        }

        $this->logger->debug(
            'received job [' . $job['_id'] . '], write in systemv message queue',
            ['category' => get_class($this)]
        );

        @msg_send(
            $this->queue,
            WorkerManager::TYPE_JOB,
            $job
        );

        return $this;
    }

    protected function catchSignal(): self
    {
        pcntl_async_signals(true);

        pcntl_signal(SIGTERM, [$this, 'cleanup']);
        pcntl_signal(SIGINT, [$this, 'cleanup']);
        pcntl_signal(SIGCHLD, [$this, 'exitWorkerManager']);

        return $this;
    }

    protected function endWaitingJobsAndEndWorkerManager(): void
    {
        $result = $this->db->{$this->scheduler->getJobQueue()}->updateMany(
            [
                'status' => JobInterface::STATUS_WAITING,
            ],
            [
                '$set' => [
                    'status' => JobInterface::STATUS_FAILED,
                    'worker' => null,
                    'ended' => new UTCDateTime(),
                ],
            ]
        );

        $this->logger->warning(
            'failed [{jobs}] waiting jobs because no worker was processing jobs',
            [
                'category' => get_class($this),
                'jobs' => $result->getModifiedCount(),
            ]
        );

        if ($this->manager_pid !== null) {
            $this->exitWorkerManager(
                SIGTERM,
                ['pid' => $this->manager_pid]
            );
        }
    }
}
