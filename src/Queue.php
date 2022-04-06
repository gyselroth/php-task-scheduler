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
use Psr\Log\LoggerInterface;
use TaskScheduler\Exception\InvalidArgumentException;
use TaskScheduler\Exception\SpawnForkException;

class Queue
{
    use InjectTrait;
    use EventsTrait;

    /**
     * Orphaned job timeout.
     */
    public const OPTION_ORPHANED_JOB_TIMEOUT = 'orphaned_job_timeout';

    /**
     * Orphaned worker timeout.
     */
    public const OPTION_ORPHANED_WORKER_TIMEOUT = 'orphaned_worker_timeout';

    /**
     * Minimum waiting jobs for worker timout.
     */
    public const OPTION_WAITING_JOBS_FOR_WORKER_TIMEOUT = 'waiting_jobs_for_worker_timeout';

    /**
     * Amount of processing jobs for worker timout.
     */
    public const OPTION_PROCESSING_JOBS_FOR_WORKER_TIMEOUT = 'processing_for_jobs_worker_timeout';

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
     * Orphaned job timeout.
     *
     * @var int
     */
    protected $orphaned_job_timeout = 30;

    /**
     * Orphaned worker timeout.
     *
     * @var int
     */
    protected $orphaned_worker_timeout = 600;

    /**
     * Minimum waiting jobs for worker timeout.
     *
     * @var int
     */
    protected $waiting_jobs_for_worker_timeout = 5;

    /**
     * Amount of processing jobs for worker timeout.
     *
     * @var int
     */
    protected $processing_for_jobs_worker_timeout = 0;

    /**
     * Init queue.
     */
    public function __construct(Scheduler $scheduler, Database $db, WorkerFactoryInterface $factory, LoggerInterface $logger, ?Emitter $emitter = null, array $config = [])
    {
        $this->scheduler = $scheduler;
        $this->db = $db;
        $this->logger = $logger;
        $this->factory = $factory;
        $this->emitter = $emitter ?? new Emitter();
        $this->setOptions($config);
    }

    /**
     * Set options.
     */
    public function setOptions(array $config = []): self
    {
        foreach ($config as $option => $value) {
            switch ($option) {
                case self::OPTION_ORPHANED_JOB_TIMEOUT:
                case self::OPTION_ORPHANED_WORKER_TIMEOUT:
                case self::OPTION_WAITING_JOBS_FOR_WORKER_TIMEOUT:
                case self::OPTION_PROCESSING_JOBS_FOR_WORKER_TIMEOUT:
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
            if (msg_receive($this->queue, 0, $type, 16384, $msg, true, MSG_IPC_NOWAIT)) {
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
                if (time() - $start_job >= $this->orphaned_job_timeout) {
                    $this->rescheduleOrphanedJobs();
                    $start_job = time();
                }

                if (time() - $start_worker >= $this->orphaned_worker_timeout) {
                    $this->checkForOrphanedWorkers();
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

        $result = $this->db->{$this->scheduler->getJobQueue()}->updateMany([
            'status' => JobInterface::STATUS_PROCESSING,
            'alive' => ['$lt' => new UTCDateTime((time() - $this->orphaned_job_timeout) * 1000)],
        ], [
            '$set' => ['status' => JobInterface::STATUS_WAITING, 'worker' => null],
        ]);

        $this->logger->debug('found [{jobs}] orphaned jobs, reset state to waiting', [
            'category' => get_class($this),
            'jobs' => $result->getMatchedCount(),
        ]);

        return $this;
    }

    protected function checkForOrphanedWorkers(): self
    {
        $this->logger->debug('looking for orphaned workers', [
            'category' => get_class($this),
        ]);

        $waiting = $this->db->{$this->scheduler->getJobQueue()}->find([
            'status' => JobInterface::STATUS_WAITING,
        ])->toArray();

        $waiting = count($waiting);

        $this->logger->debug('found [{jobs}] waiting jobs', [
            'category' => get_class($this),
            'jobs' => $waiting,
        ]);

        $processing = $this->db->{$this->scheduler->getJobQueue()}->find([
            'status' => JobInterface::STATUS_PROCESSING,
        ])->toArray();

        $processing = count($processing);

        $this->logger->debug('found [{jobs}] processing jobs', [
            'category' => get_class($this),
            'jobs' => $processing,
        ]);

        if ($waiting > $this->waiting_jobs_for_worker_timeout && $processing === $this->processing_for_jobs_worker_timeout) {
            $this->exitWorkerManager(SIGCHLD, ['pid' => $this->manager_pid]);
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
}
