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
    public const OPTION_PM = 'process_handling';
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
                case 'max_children':
                case 'min_children':
                    if (!is_int($value)) {
                        throw new InvalidArgumentException($option.' needs to be an integer');
                    }

                    $this->{$option} = $value;

                break;
                case 'pm':
                    if (!defined('PM_'.strtoupper($value))) {
                        throw new InvalidArgumentException($option.' is not a valid process handling type (static, dynamic, ondemand)');
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
     * Start initial workers.
     */
    protected function startInitialWorkers()
    {
        $this->logger->debug('start initial ['.$this->min_children.'] child processes', [
            'category' => get_class($this),
            'pm' => $this->process,
        ]);

        $pids = [];

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
     */
    protected function startWorker(?array $job = null): int
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

            if (count($this->forks) < $this->max_children && self::PM_STATIC !== $this->pm) {
                $this->logger->debug('max_children ['.$this->max_children.'] processes not reached ['.count($this->forks).'], start new worker', [
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

        foreach ($this->forks as $pid) {
            $this->logger->debug('forward signal ['.$sig.'] to child process ['.$pid.']', [
                'category' => get_class($this),
                'pm' => $this->process,
            ]);

            posix_kill($pid, $sig);
        }
    }
}
