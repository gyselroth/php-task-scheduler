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

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Database;
use Psr\Log\LoggerInterface;
use TaskScheduler\Exception\InvalidArgumentException;
use TaskScheduler\Exception\QueueRuntimeException;

class Queue
{
    /**
     * Process identifier.
     */
    public const MAIN_PROCESS = 'main';

    /**
     * Queue options.
     */
    public const OPTION_PM = 'pm';
    public const OPTION_MAX_CHILDREN = 'max_children';
    public const OPTION_MIN_CHILDREN = 'min_children';

    /**
     * Process handling.
     */
    public const PM_DYNAMIC = 'dynamic';
    public const PM_STATIC = 'static';
    public const PM_ONDEMAND = 'ondemand';

    /**
     * Process management.
     *
     * @var string
     */
    protected $pm = self::PM_DYNAMIC;

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
     * Max children.
     *
     * @var int
     */
    protected $max_children = 2;

    /**
     * Min children.
     *
     * @var int
     */
    protected $min_children = 1;

    /**
     * Forks.
     *
     * @var array
     */
    protected $forks = [];

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
     * Main process name.
     *
     * @var string
     */
    protected $process;

    /**
     * Init queue.
     */
    public function __construct(Scheduler $scheduler, Database $db, WorkerFactoryInterface $factory, LoggerInterface $logger, array $config = [])
    {
        $this->scheduler = $scheduler;
        $this->db = $db;
        $this->logger = $logger;
        $this->setOptions($config);
        $this->process = self::MAIN_PROCESS;
        $this->factory = $factory;

        $this->jobs = new MessageQueue($db, $scheduler->getJobQueue(), $scheduler->getJobQueueSize(), $logger);
        $this->events = new MessageQueue($db, $scheduler->getEventQueue(), $scheduler->getEventQueueSize(), $logger);
    }

    /**
     * Set options.
     *
     *
     * @return Queue
     */
    public function setOptions(array $config = []): self
    {
        foreach ($config as $option => $value) {
            switch ($option) {
                case self::OPTION_MAX_CHILDREN:
                case self::OPTION_MIN_CHILDREN:
                    if (!is_int($value)) {
                        throw new InvalidArgumentException($option.' needs to be an integer');
                    }

                    $this->{$option} = $value;

                break;
                case self::OPTION_PM:
                    if (!defined('self::PM_'.strtoupper($value))) {
                        throw new InvalidArgumentException($value.' is not a valid process handling type (static, dynamic, ondemand)');
                    }

                    $this->{$option} = $value;

                break;
                default:
                    throw new InvalidArgumentException('invalid option '.$option.' given');
            }
        }

        if ($this->min_children > $this->max_children) {
            throw new InvalidArgumentException('option min_children must not be greater than option max_children');
        }

        return $this;
    }

    /**
     * Startup (blocking process).
     */
    public function process(): void
    {
        try {
            $this->spawnInitialWorkers();
            $this->main();
        } catch (\Exception $e) {
            $this->logger->error('main() throw an exception, cleanup and exit', [
                'class' => get_class($this),
                'exception' => $e,
                'pm' => $this->process,
            ]);

            $this->cleanup(SIGTERM);
        }
    }

    /**
     * Cleanup and exit.
     */
    public function cleanup(int $sig)
    {
        $this->handleSignal($sig);
        exit();
    }

    /**
     * Wait for child and terminate.
     */
    public function exitChild(int $sig, array $pid): self
    {
        $this->logger->debug('worker ['.$pid['pid'].'] exit with ['.$sig.']', [
            'category' => get_class($this),
            'pm' => $this->process,
        ]);

        pcntl_waitpid($pid['pid'], $status, WNOHANG | WUNTRACED);

        foreach ($this->forks as $id => $pid) {
            if ($pid === $pid['pi']) {
                unset($this->forks[$id]);
            }
        }

        return $this;
    }

    /**
     * Count children.
     */
    public function count(): int
    {
        return count($this->forks);
    }

    /**
     * Start initial workers.
     */
    protected function spawnInitialWorkers()
    {
        $this->logger->debug('spawn initial ['.$this->min_children.'] workers', [
            'category' => get_class($this),
            'pm' => $this->process,
        ]);

        if (self::PM_DYNAMIC === $this->pm || self::PM_STATIC === $this->pm) {
            for ($i = $this->count(); $i < $this->min_children; ++$i) {
                $this->spawnWorker();
            }
        }
    }

    /**
     * Start worker.
     *
     * @see https://github.com/mongodb/mongo-php-driver/issues/828
     * @see https://github.com/mongodb/mongo-php-driver/issues/174
     */
    protected function spawnWorker()
    {
        $id = new ObjectId();
        $pid = pcntl_fork();

        if (-1 === $pid) {
            throw new QueueRuntimeException('failed to spawn new worker');
        }

        $this->forks[(string) $id] = $pid;
        if (!$pid) {
            $this->factory->build($id)->start();
            exit();
        }

        $this->logger->debug('spawn worker ['.$id.'] with pid ['.$pid.']', [
            'category' => get_class($this),
            'pm' => $this->process,
        ]);

        return $pid;
    }

    /**
     * Get forks (array of pid's).
     *
     * @return int[]
     */
    protected function getForks(): array
    {
        return $this->forks;
    }

    /**
     * This method may seem useless but is actually very useful to mock the loop.
     */
    protected function loop(): bool
    {
        return true;
    }

    /**
     * Fork handling, blocking process.
     */
    protected function main(): void
    {
        $cursor_jobs = $this->jobs->getCursor([
            '$or' => [
                ['status' => JobInterface::STATUS_WAITING],
                ['status' => JobInterface::STATUS_POSTPONED],
            ],
        ]);

        $cursor_events = $this->events->getCursor([
            'status' => JobInterface::STATUS_CANCELED,
            'timestamp' => ['$gte' => new UTCDateTime()],
        ]);

        $this->catchSignal();

        while ($this->loop()) {
            $event = $cursor_events->current();
            $this->events->next($cursor_events, function () {
                $this->main();
            });

            if (null === $event) {
                if ($cursor_events->getInnerIterator()->isDead()) {
                    $this->logger->error('event queue cursor is dead, is it a capped collection?', [
                        'category' => get_class($this),
                        'pm' => $this->process,
                    ]);

                    $this->events->create();

                    $this->main();

                    break;
                }
            } else {
                $this->handleCancel($event);
            }

            if (null === $cursor_jobs->current()) {
                if ($cursor_jobs->getInnerIterator()->isDead()) {
                    $this->logger->error('job queue cursor is dead, is it a capped collection?', [
                        'category' => get_class($this),
                        'pm' => $this->process,
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

            $this->manageChildren($job);
        }
    }

    /**
     * Handle cancel event.
     */
    protected function handleCancel(array $event): self
    {
        $process = $this->scheduler->getJob($event['job']);

        $this->logger->debug('received cancel event for job ['.$event['job'].'] running on worker ['.$process->getWorker().']', [
            'category' => get_class($this),
        ]);

        $worker = $process->getWorker();

        if (isset($this->forks[(string) $worker])) {
            $this->logger->debug('found running worker ['.$process->getWorker().'] on this queue node, terminate it now', [
                'category' => get_class($this),
            ]);

            posix_kill($this->forks[(string) $worker], SIGKILL);
        }

        return $this;
    }

    /**
     * Manage children.
     */
    protected function manageChildren(array $job): self
    {
        if ($this->count() < $this->max_children && self::PM_STATIC !== $this->pm) {
            $this->logger->debug('max_children ['.$this->max_children.'] workers not reached ['.$this->count().'], spawn new worker', [
                'category' => get_class($this),
                'pm' => $this->process,
            ]);

            $this->spawnWorker();
        } elseif (true === $job['options'][Scheduler::OPTION_IGNORE_MAX_CHILDREN]) {
            $this->logger->debug('job ['.$job['_id'].'] deployed with ignore_max_children, spawn new worker', [
                'category' => get_class($this),
                'pm' => $this->process,
            ]);

            $this->spawnWorker();
        } else {
            $this->logger->debug('max children ['.$this->max_children.'] reached for job ['.$job['_id'].'], do not spawn new worker', [
                'category' => get_class($this),
                'pm' => $this->process,
            ]);
        }

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
        pcntl_signal(SIGCHLD, [$this, 'exitChild']);

        return $this;
    }

    /**
     * Cleanup.
     */
    protected function handleSignal(int $sig): void
    {
        $this->logger->debug('received signal ['.$sig.']', [
            'category' => get_class($this),
            'pm' => $this->process,
        ]);

        foreach ($this->getForks() as $id => $pid) {
            $this->logger->debug('forward signal ['.$sig.'] to worker ['.$id.'] running with pid ['.$pid.']', [
                'category' => get_class($this),
                'pm' => $this->process,
            ]);

            posix_kill($pid, $sig);
        }
    }
}
