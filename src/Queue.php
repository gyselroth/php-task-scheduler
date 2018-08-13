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
use MongoDB\Database;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class Queue extends AbstractQueue
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
     * Collection name.
     *
     * @var string
     */
    protected $collection_name = 'queue';

    /**
     * Process management.
     *
     * @var string
     */
    protected $pm = self::PM_DYNAMIC;

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
     * Init queue.
     *
     * @param Scheduler              $scheduler
     * @param Database               $db
     * @param WorkerFactoryInterface $factory
     * @param LoggerInterface        $logger
     * @param ContainerInterface     $container
     * @param array                  $options
     */
    public function __construct(Scheduler $scheduler, Database $db, WorkerFactoryInterface $factory, LoggerInterface $logger, ?ContainerInterface $container = null, array $config = [])
    {
        $this->scheduler = $scheduler;
        $this->db = $db;
        $this->logger = $logger;
        $this->container = $container;
        $this->collection_name = $scheduler->getCollection();
        $this->setOptions($config);
        $this->process = self::MAIN_PROCESS;
        $this->factory = $factory;
    }

    /**
     * Set options.
     *
     * @param array $config
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
            $this->startInitialWorkers();
            $this->main();
        } catch (\Exception $e) {
            $this->cleanup(SIGTERM);
        }
    }

    /**
     * Cleanup and exit.
     *
     * @param int $sig
     */
    public function cleanup(int $sig)
    {
        $this->handleSignal($sig);
        exit();
    }

    /**
     * Wait for child and terminate.
     *
     * @param int   $sig
     * @param array $pid
     *
     * @return Queue
     */
    public function exitChild(int $sig, array $pid): self
    {
        pcntl_waitpid($pid['pid'], $status, WNOHANG | WUNTRACED);

        if (isset($this->forks[$pid['pid']])) {
            unset($this->forks[$pid['pid']]);
        }

        return $this;
    }

    /**
     * Start initial workers.
     */
    protected function startInitialWorkers()
    {
        $this->logger->debug('start initial ['.$this->min_children.'] child processes', [
            'category' => get_class($this),
            'pm' => $this->process,
        ]);

        if (self::PM_DYNAMIC === $this->pm || self::PM_STATIC === $this->pm) {
            for ($i = 0; $i < $this->min_children; ++$i) {
                $this->startWorker();
            }
        }
    }

    /**
     * Start worker.
     *
     * @see https://github.com/mongodb/mongo-php-driver/issues/828
     * @see https://github.com/mongodb/mongo-php-driver/issues/174
     *
     * @param array $job
     *
     * @return int
     */
    protected function startWorker(?array $job = null)
    {
        $pid = pcntl_fork();
        $this->forks[] = $pid;

        if (-1 === $pid) {
            throw new Exception\Runtime('failed to start new worker');
        }
        if (!$pid) {
            $worker = $this->factory->build()->start();
            exit();
        }

        $this->logger->debug('start worker process ['.$pid.']', [
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
    protected function main()
    {
        $cursor = $this->getCursor();
        $this->catchSignal();

        while (true) {
            if (null === $cursor->current()) {
                if ($cursor->getInnerIterator()->isDead()) {
                    $this->logger->error('job queue cursor is dead, is it a capped collection?', [
                        'category' => get_class($this),
                        'pm' => $this->process,
                    ]);

                    $this->createQueue();

                    return $this->main();
                }

                $this->retrieveNextJob($cursor);

                continue;
            }

            $job = $cursor->current();
            $this->retrieveNextJob($cursor);

            if ($this->manager->getProcessCount() < $this->max_children && self::PM_STATIC !== $this->pm) {
                $this->logger->debug('max_children ['.$this->max_children.'] processes not reached ['.$this->manager->getProcessCount().'], start new worker', [
                    'category' => get_class($this),
                    'pm' => $this->process,
                ]);

                $this->startWorker();
            } elseif (isset($job[Scheduler::OPTION_IGNORE_MAX_CHILDREN]) && true === $job[Scheduler::OPTION_IGNORE_MAX_CHILDREN]) {
                $this->logger->debug('job ['.$job['_id'].'] deployed with ignore_max_children, start new worker', [
                    'category' => get_class($this),
                    'pm' => $this->process,
                ]);

                $this->startWorker($job);
            } else {
                $this->logger->debug('max children ['.$this->max_children.'] reached for job ['.$job['_id'].'], do not start new worker', [
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
     *
     * @param int $sig
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
