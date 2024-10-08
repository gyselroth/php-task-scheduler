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

    /**
     * Orphaned timeout.
     */
    public const OPTION_ORPHANED_TIMEOUT = 'orphaned_timeout';

    /**
     * Endless worker timeout.
     */
    public const OPTION_ENDLESS_WORKER_TIMEOUT = 'endless_worker_timeout';

    /**
     * Minimum waiting jobs for endless worker timout.
     */
    public const OPTION_WAITING_JOBS_FOR_ENDLESS_WORKER = 'waiting_jobs_for_endless_worker';

    /**
     * Check whether a waiting job is running longer than defined time. If a waiting job runs longer and
     * no job is running restart WorkerManager.
     */
    public const OPTION_WAITING_TIME_FOR_ENDLESS_WORKER = 'waiting_time_for_endless_worker';

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
     * @var ContainerInterface
     */
    protected $container;

    /**
     * Worker factory.
     *
     * @var WorkerFactoryInterface
     */
    protected $factory;

    /**
     * Worker manager pid.
     *
     * @var int
     */
    protected $manager_pid;

    /**
     * Sysmfsg queue.
     *
     * @var resource
     */
    protected $queue;

    /**
     * Scheduler.
     *
     * @var Scheduler
     */
    protected $scheduler;

    /**
     * Orphaned timeout.
     *
     * @var int
     */
    protected $orphaned_timeout = 30;

    /**
     * Endless worker timeout.
     *
     * @var int
     */
    protected $endless_worker_timeout = 600;

    /**
     * Minimum waiting jobs endless worker restart.
     *
     * @var int
     */
    protected $waiting_jobs_for_endless_worker = 5;

    /**
     * Check whether a waiting job is running longer than defined time. If a waiting job runs longer and
     * no job is running restart WorkerManager.
     *
     * @var int
     */
    protected $waiting_time_for_endless_worker = 900;

    /**
     * Are there waiting jobs without processing jobs.
     *
     * @var bool
     */
    protected $waiting_jobs_without_processing = false;

    /**
     * Jobs with waiting status that have run into timeout.
     *
     * @var array
     */
    protected $waiting_jobs = [];

    /**
     * Init queue.
     */
    public function __construct(Scheduler $scheduler, Database $db, WorkerFactoryInterface $factory, LoggerInterface $logger, ?Emitter $emitter = null, array $config = [], ?ContainerInterface $container = null)
    {
        $this->scheduler = $scheduler;
        $this->db = $db;
        $this->logger = $logger;
        $this->factory = $factory;
        $this->emitter = $emitter ?? new Emitter();
        $this->setOptions($config);
        $this->container = $container;
    }

    /**
     * Set options.
     */
    public function setOptions(array $config = []): self
    {
        foreach ($config as $option => $value) {
            switch ($option) {
                case self::OPTION_ORPHANED_TIMEOUT:
                case self::OPTION_ENDLESS_WORKER_TIMEOUT:
                case self::OPTION_WAITING_JOBS_FOR_ENDLESS_WORKER:
                case self::OPTION_WAITING_TIME_FOR_ENDLESS_WORKER:
                    if (!is_int($value)) {
                        throw new InvalidArgumentException($option.' needs to be an integer');
                    }

                    $this->{$option} = $value;

                    break;
                default:
                    throw new InvalidArgumentException('invalid option '.$option.' given');
            }
        }

        return $this;
    }

    /**
     * Startup (blocking process).
     */
    public function process(): void
    {
        try {
            $this->queue = msg_get_queue(ftok(__FILE__, 't'));
            $this->catchSignal();
            $this->initWorkerManager();
            $this->main();
        } catch (\Exception $e) {
            $this->logger->error('main() throw an exception, cleanup and exit', [
                'class' => get_class($this),
                'exception' => $e,
            ]);

            $this->cleanup(SIGTERM);
        }
    }

    /**
     * Wait for worker manager.
     */
    public function exitWorkerManager(int $sig, array $pid): void
    {
        $this->logger->debug('fork manager ['.$pid['pid'].'] exit with ['.$sig.']', [
            'category' => get_class($this),
        ]);

        pcntl_waitpid($pid['pid'], $status, WNOHANG | WUNTRACED);
        $this->cleanup(SIGTERM);
    }

    /**
     * Cleanup.
     */
    public function cleanup(int $sig): void
    {
        if (null !== $this->manager_pid) {
            $this->logger->debug('received exit signal ['.$sig.'], forward signal to the fork manager ['.$sig.']', [
                'category' => get_class($this),
            ]);

            posix_kill($this->manager_pid, $sig);
        }

        $this->exit();
    }

    /**
     * Fork a worker manager.
     */
    protected function initWorkerManager()
    {
        $pid = pcntl_fork();
        $this->manager_pid = $pid;

        if (-1 === $pid) {
            throw new SpawnForkException('failed to spawn fork manager');
        }

        if (!$pid) {
            $manager = $this->factory->buildManager();
            $manager->process();
            exit();
        }
    }

    /**
     * Fetch events.
     */
    protected function fetchEvents()
    {
        while ($this->loop()) {
            if (msg_receive($this->queue, 0, $type, 16384, $msg, true, MSG_IPC_NOWAIT | MSG_NOERROR)) {
                $this->logger->debug('received systemv message type ['.$type.']', [
                    'category' => get_class($this),
                ]);

                switch ($type) {
                    case WorkerManager::TYPE_JOB:
                        //handled by worker manager
                        break;
                    case WorkerManager::TYPE_WORKER_SPAWN:
                        $this->emitter->emit('taskscheduler.onWorkerSpawn', $msg['_id']);

                        break;
                    case WorkerManager::TYPE_WORKER_KILL:
                        $this->emitter->emit('taskscheduler.onWorkerKill', $msg['_id']);

                        break;
                    default:
                        $this->logger->warning('received unknown systemv message type ['.$type.']', [
                            'category' => get_class($this),
                        ]);
                }
            } else {
                return;
            }
        }
    }

    /**
     * Fork handling, blocking process.
     */
    protected function main(): void
    {
        $this->logger->info('start job listener', [
            'category' => get_class($this),
        ]);

        $this->catchSignal();

        $cursor_watch = $this->db->{$this->scheduler->getJobQueue()}->watch([], ['fullDocument' => 'updateLookup']);
        $cursor_fetch = $this->db->{$this->scheduler->getJobQueue()}->find([
            '$or' => [
                ['status' => JobInterface::STATUS_WAITING],
                ['status' => JobInterface::STATUS_POSTPONED],
            ],
        ]);

        foreach ($cursor_fetch as $job) {
            $this->fetchEvents();
            $this->handleJob((array) $job);
        }

        $start_job = $start_worker = time();

        $cursor_watch->rewind();
        while ($this->loop()) {
            if (!$cursor_watch->valid()) {
                if (time() - $start_job >= $this->orphaned_timeout) {
                    $this->rescheduleOrphanedJobs();
                    $start_job = time();
                }

                if (time() - $start_worker >= $this->endless_worker_timeout) {
                    $this->checkEndlessRunningWorkers();
                    $start_worker = time();
                }

                $cursor_watch->next();

                continue;
            }

            $this->fetchEvents();
            $event = $cursor_watch->current();
            $cursor_watch->next();

            if (null === $event || !isset($event['fullDocument'])) {
                continue;
            }
            $this->handleJob((array) $event['fullDocument']);
        }
    }

    protected function rescheduleOrphanedJobs(): self
    {
        $this->logger->debug('looking for orphaned jobs', [
            'category' => get_class($this),
        ]);

        $alive_utc_datetime = new UTCDateTime((time() - $this->orphaned_timeout) * 1000);

        foreach ($this->scheduler->getOrphanedProcs($alive_utc_datetime) as $orphaned_proc) {
            $has_child_procs = false;

            foreach($this->scheduler->getChildProcs($orphaned_proc->getId()) as $child_proc) {
                $has_child_procs = true;
            }

            if ($has_child_procs) {
                $result = $this->db->{$this->scheduler->getJobQueue()}->updateMany([
                    'status' => JobInterface::STATUS_PROCESSING,
                    'alive' => ['$lt' => $alive_utc_datetime],
                    'data.parent' => $orphaned_proc->getId()
                ], [
                    '$set' => ['status' => JobInterface::STATUS_FAILED],
                ]);

                $this->logger->warning('found [{jobs}] orphaned child job, set state to failed', [
                    'category' => get_class($this),
                    'jobs' => $result->getMatchedCount(),
                ]);

                if ($result->getMatchedCount() === 0) {
                    $this->logger->warning('no orphaned child jobs found for orphaned parent job ['.$orphaned_proc->getId().'] set state of parent job to done', [
                        'category' => get_class($this),
                    ]);

                    $this->db->{$this->scheduler->getJobQueue()}->updateMany([
                        '_id' => $orphaned_proc->getId(),
                    ], [
                        '$set' => [
                            'status' => JobInterface::STATUS_DONE,
                            'ended' => $set['ended'] = new UTCDateTime()
                        ],
                    ]);

                    msg_send($this->queue, WorkerManager::TYPE_WORKER_ORPHANED_JOB, $orphaned_proc->toArray());
                } else {
                    $this->failJobAndNotifyJobClass($orphaned_proc);

                    $this->logger->warning('set state of parent job ['.$orphaned_proc->getId().'] to failed', [
                        'category' => get_class($this),
                    ]);
                }
            } else {
                $result = $this->failJobAndNotifyJobClass($orphaned_proc);

                $this->logger->warning('found [{jobs}] orphaned parent job with jobId ['.$orphaned_proc->getId().'], reset state to failed', [
                    'category' => get_class($this),
                    'jobs' => $result->getMatchedCount(),
                ]);
            }
        }

        return $this;
    }

    protected function failJobAndNotifyJobClass(Process $job): UpdateResult
    {
        $job_id = $job->getId();

        $result = $this->db->{$this->scheduler->getJobQueue()}->updateMany([
            '_id' => $job_id,
        ], [
            '$set' => ['status' => JobInterface::STATUS_FAILED],
        ]);

        if ($this->container !== null) {
            $instance = $this->container->get($job->getClass());
            sleep(rand(1, 5));
            $job = $this->scheduler->getJob($job_id)->toArray();

            if (method_exists($instance, 'notification')) {
                if (!isset($job['notification_sent'])) {
                    $instance->notification(JobInterface::STATUS_FAILED, $job);

                    $this->db->{$this->scheduler->getJobQueue()}->updateMany([
                        '_id' => $job_id,
                    ], [
                        '$set' => ['notification_sent' => true],
                    ]);
                }
            } else {
                $this->logger->info('method notification() does not exists on instance', [
                    'category' => get_class($this),
                ]);
            }
        }

        return $result;
    }

    protected function checkEndlessRunningWorkers(): self
    {
        $this->logger->debug('looking for endless running workers', [
            'category' => get_class($this),
        ]);

        $waiting_jobs = $this->db->{$this->scheduler->getJobQueue()}->find([
            'status' => JobInterface::STATUS_WAITING,
        ])->toArray();

        $processing_jobs = $this->db->{$this->scheduler->getJobQueue()}->find([
            'status' => JobInterface::STATUS_PROCESSING,
        ])->toArray();

        $number_of_waiting_jobs = count($waiting_jobs);
        $number_of_processing_jobs = count($processing_jobs);

        $this->logger->debug('found [{jobs_waiting}] waiting jobs and [{jobs_processing}] processing jobs', [
            'category' => get_class($this),
            'jobs_waiting' => $number_of_waiting_jobs,
            'jobs_processing' => $number_of_processing_jobs,
        ]);

        if ($number_of_waiting_jobs > $this->waiting_jobs_for_endless_worker && 0 === $number_of_processing_jobs) {
            $this->endWaitingJobsAndEndWorkerManager();
        } elseif ($number_of_waiting_jobs > 0 && 0 === $number_of_processing_jobs) {
            foreach ($waiting_jobs as $job) {
                if (($job['started']) !== null) {
                    $started = $job['started']->toDateTime()->getTimestamp();

                    if ((time() - $started) > $this->waiting_time_for_endless_worker) {
                        if ($this->waiting_jobs_without_processing) {
                            if (in_array($job['_id'], $this->waiting_jobs)) {
                                $this->logger->warning('found same waiting job with id ['.(string)$job['_id'].'] after ['.$this->waiting_time_for_endless_worker.'s] without processing jobs. exit WorkerManager.');

                                $this->endWaitingJobsAndEndWorkerManager();
                            } else {
                                $this->logger->warning('found waiting job ['.(string)$job['_id'].'] without processing jobs. check again after '.$this->endless_worker_timeout.'s');

                                $this->waiting_jobs[] = (string)$job['_id'];
                                $this->waiting_jobs_without_processing = true;
                            }
                        } else {
                            $this->logger->warning('found waiting job ['.(string)$job['_id'].'] without processing jobs. check again after '.$this->endless_worker_timeout.'s');

                            $this->waiting_jobs[] = (string)$job['_id'];
                            $this->waiting_jobs_without_processing = true;
                        }
                    } else {
                        $this->logger->info('found waiting job ['.(string)$job['_id'].'] without processing job. but waiting job timeout is not reached.');
                    }
                }
            }
        }

        return $this;
    }

    /**
     * Handle job.
     */
    protected function handleJob(array $job): self
    {
        $this->logger->debug('received job ['.$job['_id'].'], write in systemv message queue', [
            'category' => get_class($this),
        ]);

        msg_send($this->queue, WorkerManager::TYPE_JOB, $job);

        return $this;
    }

    /**
     * Catch signals and cleanup.
     */
    protected function catchSignal(): self
    {
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, [$this, 'cleanup']);
        pcntl_signal(SIGINT, [$this, 'cleanup']);
        pcntl_signal(SIGCHLD, [$this, 'exitWorkerManager']);

        return $this;
    }

    /*
     * Fail waiting jobs and exit WorkerManager
     */
    protected function endWaitingJobsAndEndWorkerManager(): void
    {
        $this->db->{$this->scheduler->getJobQueue()}->updateMany([
            'status' => JobInterface::STATUS_WAITING,
        ], [
            '$set' => ['status' => JobInterface::STATUS_FAILED, 'worker' => null],
        ]);

        $this->exitWorkerManager(SIGCHLD, ['pid' => $this->manager_pid]);
    }
}
