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
use MongoDB\BSON\UTCDateTime;
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
     * Jobs queue.
     *
     * @var MessageQueue
     */
    protected $jobs;

    /**
     * Events queue.
     *
     * @var MessageQueue
     */
    protected $events;

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
        $this->jobs = new MessageQueue($db, $scheduler->getJobQueue(), $scheduler->getJobQueueSize(), $logger);
        $this->events = new MessageQueue($db, $scheduler->getEventQueue(), $scheduler->getEventQueueSize(), $logger);
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
     * Fork handling, blocking process.
     */
    protected function main(): void
    {
        $this->logger->info('start job listener', [
            'category' => get_class($this),
        ]);

        $cursor_jobs = $this->jobs->getCursor([
            '$or' => [
                ['status' => JobInterface::STATUS_WAITING],
                ['status' => JobInterface::STATUS_POSTPONED],
            ],
        ]);

        $cursor_events = $this->events->getCursor([
            'timestamp' => ['$gte' => new UTCDateTime()],
            'job' => ['$exists' => true],
        ]);

        $this->catchSignal();

        while ($this->loop()) {
            while ($this->loop()) {
                if (msg_receive($this->queue, 0, $type, 16384, $msg, true, 0)) {
                    $this->logger->debug('received systemv message type ['.$type.']', [
                        'category' => get_class($this),
                    ]);

                    switch ($type) {
                        case WorkerManager::TYPE_JOB:
                        case WorkerManager::TYPE_EVENT:
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
                    break;
                }
            }

            while ($this->loop()) {
                if (null === $cursor_events->current()) {
                    if ($cursor_events->getInnerIterator()->isDead()) {
                        $this->logger->error('event queue cursor is dead, is it a capped collection?', [
                            'category' => get_class($this),
                        ]);

                        $this->events->create();

                        $this->main();

                        break;
                    }

                    $this->events->next($cursor_events, function () {
                        $this->main();
                    });
                }

                $event = $cursor_events->current();
                $this->events->next($cursor_events, function () {
                    $this->main();
                });

                if (null === $event) {
                    break;
                }

                $this->handleEvent($event);
            }

            if (null === $cursor_jobs->current()) {
                if ($cursor_jobs->getInnerIterator()->isDead()) {
                    $this->logger->error('job queue cursor is dead, is it a capped collection?', [
                        'category' => get_class($this),
                    ]);

                    $this->jobs->create();
                    $this->main();

                    break;
                }

                $this->jobs->next($cursor_jobs, function () {
                    $this->main();
                });

                continue;
            }

            $job = $cursor_jobs->current();

            $this->jobs->next($cursor_jobs, function () {
                $this->main();
            });

            $this->handleJob($job);
        }
    }

    /**
     * Handle events.
     */
    protected function handleEvent(array $event): self
    {
        $this->emit($this->scheduler->getJob($event['job']));

        if ($event['status'] > JobInterface::STATUS_POSTPONED) {
            $this->logger->debug('received event ['.$event['status'].'] for job ['.$event['job'].'], write into systemv queue', [
                'category' => get_class($this),
            ]);

            msg_send($this->queue, WorkerManager::TYPE_EVENT, $event);
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

        msg_send($this->queue, WorkerManager::TYPE_JOB, [
            '_id' => $job['_id'],
            'options' => $job['options'],
        ]);

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
