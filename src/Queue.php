<?php

declare(strict_types=1);

/**
 * TaskScheduler
 *
 * @author      gyselroth™  (http://www.gyselroth.com)
 * @copyright   Copryright (c) 2017-2021 gyselroth GmbH (https://gyselroth.com)
 * @license     MIT https://opensource.org/licenses/MIT
 */

namespace TaskScheduler;

use League\Event\Emitter;
use MongoDB\Database;
use Psr\Log\LoggerInterface;
use TaskScheduler\Exception\SpawnForkException;

class Queue
{
    use InjectTrait;
    use EventsTrait;

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
     * Init queue.
     */
    public function __construct(Scheduler $scheduler, Database $db, WorkerFactoryInterface $factory, LoggerInterface $logger, ?Emitter $emitter = null)
    {
        $this->scheduler = $scheduler;
        $this->db = $db;
        $this->logger = $logger;
        $this->factory = $factory;
        $this->emitter = $emitter ?? new Emitter();
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
     * Fetch events
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

        $cursor_watch = $this->db->{$this->scheduler->getJobQueue()}->watch([],['fullDocument' => 'updateLookup']);
        $cursor_fetch = $this->db->{$this->scheduler->getJobQueue()}->find([
            '$or' => [
                ['status' => JobInterface::STATUS_WAITING],
                ['status' => JobInterface::STATUS_POSTPONED],
            ],
        ]);

        foreach($cursor_fetch as $job) {
            $this->fetchEvents();
            $this->handleJob($job);
        }

        $start = time();

        $cursor_watch->rewind();
        while($this->loop()) {
            if(!$cursor_watch->valid()) {
                if(time()-$start >= 30) {
                    $this->rescheduleOrphanedJobs();
                    $start = time();
                }

                $cursor_watch->next();
                continue;
            }

            $this->fetchEvents();
            $cursor_watch->next();
            $event = $cursor_watch->current();

            if($event === null) {
                continue;
            }

            $this->handleJob($event['fullDocument']);
        }
    }

    protected function rescheduleOrphanedJobs(): self
    {
        $this->logger->debug('looking for orphaned jobs', [
            'category' => get_class($this),
        ]);

        $result = $this->db->{$this->scheduler->getJobQueue()}->updateMany([
            'status' => JobInterface::STATUS_PROCESSING,
            'alive' => ['$lt' => time()+30],
        ], [
            '$set' => ['status' => JobInterface::STATUS_WAITING],
        ]);

        $this->logger->debug('found [{jobs}] orphaned jobs, reset state to waiting', [
            'category' => get_class($this),
            'jobs' => $result->getMatchedCount(),
        ]);

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
