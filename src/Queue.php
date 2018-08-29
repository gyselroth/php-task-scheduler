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
        $this->spawnInitialWorkers();
        $this->main();

        $this->cleanup(SIGTERM);
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
        $this->logger->debug('child process ['.$pid['pid'].'] exit with ['.$sig.']', [
            'category' => get_class($this),
        ]);

        pcntl_waitpid($pid['pid'], $status, WNOHANG | WUNTRACED);

        if (isset($this->forks[$pid['pid']])) {
            unset($this->forks[$pid['pid']]);
        }

        return $this;
    }

    /**
     * Start initial workers.
     */
    protected function spawnInitialWorkers()
    {
        $this->logger->debug('spawn initial ['.$this->min_children.'] child processes', [
            'category' => get_class($this),
            'pm' => $this->process,
        ]);

        if (self::PM_DYNAMIC === $this->pm || self::PM_STATIC === $this->pm) {
            for ($i = 0; $i < $this->min_children; ++$i) {
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
    protected function spawnWorker(?array $job = null)
    {
        $pid = pcntl_fork();
        $this->forks[] = $pid;

        if (-1 === $pid) {
            throw new QueueRuntimeException('failed to spawn new worker');
        }
        if (!$pid) {
            $worker = $this->factory->build()->start();
            exit();
        }

        $this->logger->debug('spawn worker process ['.$pid.']', [
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
     * Fork handling, blocking process.
     */
    protected function main(): void
    {
        $cursor = $this->jobs->getCursor([
            '$or' => [
                ['status' => JobInterface::STATUS_WAITING],
                ['status' => JobInterface::STATUS_POSTPONED],
            ],
        ]);

        $this->catchSignal();

        while (true) {
            if (null === $cursor->current()) {
                if ($cursor->getInnerIterator()->isDead()) {
                    $this->logger->error('job queue cursor is dead, is it a capped collection?', [
                        'category' => get_class($this),
                        'pm' => $this->process,
                    ]);

                    $this->jobs->create();

                    $this->main();

                    break;
                }

                $this->jobs->next($cursor, function () {
                    $this->main();
                });

                continue;
            }

            $job = $cursor->current();
            $this->jobs->next($cursor, function () {
                $this->main();
            });

            if (count($this->forks) < $this->max_children && self::PM_STATIC !== $this->pm) {
                $this->logger->debug('max_children ['.$this->max_children.'] processes not reached ['.count($this->forks).'], spawn new worker', [
                    'category' => get_class($this),
                    'pm' => $this->process,
                ]);

                $this->spawnWorker();
            } elseif (isset($job['options'][Scheduler::OPTION_IGNORE_MAX_CHILDREN]) && true === $job['options'][Scheduler::OPTION_IGNORE_MAX_CHILDREN]) {
                $this->logger->debug('job ['.$job['_id'].'] deployed with ignore_max_children, spawn new worker', [
                    'category' => get_class($this),
                    'pm' => $this->process,
                ]);

                $this->spawnWorker($job);
            } else {
                $this->logger->debug('max children ['.$this->max_children.'] reached for job ['.$job['_id'].'], do not spawn new worker', [
                    'category' => get_class($this),
                    'pm' => $this->process,
                ]);
            }
        }
    }

    /**
     * Catch signals and cleanup.
     *
     * @return Queue
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

        foreach ($this->getForks() as $key => $pid) {
            $this->logger->debug('forward signal ['.$sig.'] to child process ['.$pid.']', [
                'category' => get_class($this),
                'pm' => $this->process,
            ]);

            posix_kill($pid, $sig);
        }
    }
}
