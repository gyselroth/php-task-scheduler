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
use MongoDB\Database;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use TaskScheduler\Exception\InvalidArgumentException;
use TaskScheduler\Exception\SpawnForkException;

class WorkerManager
{
    use InjectTrait;

    public const OPTION_PM = 'pm';
    public const OPTION_MAX_CHILDREN = 'max_children';
    public const OPTION_MIN_CHILDREN = 'min_children';

    public const PM_DYNAMIC = 'dynamic';
    public const PM_STATIC = 'static';
    public const PM_ONDEMAND = 'ondemand';

    public const TYPE_JOB = 1;
    public const TYPE_WORKER_SPAWN = 2;
    public const TYPE_WORKER_KILL = 3;
    public const TYPE_WORKER_ORPHANED_JOB = 4;

    protected $pm = self::PM_DYNAMIC;

    protected $scheduler;

    protected $db;

    protected $logger;

    protected $container;

    protected $max_children = 2;

    protected $min_children = 1;

    protected $forks = [];

    protected $job_map = [];

    protected $queue;

    protected $onhold = [];

    protected $factory;

    protected $sent_notifications = [];

    public function __construct(
        Database $db,
        WorkerFactoryInterface $factory,
        LoggerInterface $logger,
        Scheduler $scheduler,
        array $config = [],
        ?ContainerInterface $container = null
    ) {
        $this->db = $db;
        $this->logger = $logger;
        $this->setOptions($config);
        $this->factory = $factory;
        $this->scheduler = $scheduler;
        $this->container = $container;
    }

    public function setOptions(array $config = []): self
    {
        foreach ($config as $option => $value) {
            switch ($option) {
                case self::OPTION_MAX_CHILDREN:
                case self::OPTION_MIN_CHILDREN:
                    if (!is_int($value) || $value < 0) {
                        throw new InvalidArgumentException($option.' needs to be a non-negative integer');
                    }

                    $this->{$option} = $value;

                    break;
                case self::OPTION_PM:
                    if (!in_array($value, [
                        self::PM_STATIC,
                        self::PM_DYNAMIC,
                        self::PM_ONDEMAND,
                    ], true)) {
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

    public function process(): void
    {
        $key = ftok(__DIR__.DIRECTORY_SEPARATOR.'Queue.php', 't');

        if (-1 === $key) {
            throw new SpawnForkException('failed to create System V message queue key');
        }

        $this->queue = msg_get_queue($key);

        if (false === $this->queue) {
            throw new SpawnForkException('failed to create System V message queue');
        }

        $this->catchSignal();
        $this->spawnInitialWorkers();
        $this->main();
    }

    public function exitWorker(int $sig, array $pid): self
    {
        $childPid = isset($pid['pid']) ? (int) $pid['pid'] : 0;

        if ($childPid <= 0) {
            $this->logger->warning('received SIGCHLD without a valid child pid', [
                'category' => get_class($this),
                'signal' => $sig,
            ]);

            return $this;
        }

        $this->logger->debug(
            'worker ['.$childPid.'] exit with ['.$sig.']',
            ['category' => get_class($this)]
        );

        pcntl_waitpid($childPid, $status, WNOHANG | WUNTRACED);

        foreach ($this->forks as $id => $process) {
            if ((int) $process !== $childPid) {
                continue;
            }

            unset($this->forks[$id], $this->job_map[$id]);

            $this->sendMessage(self::TYPE_WORKER_KILL, [
                '_id' => new ObjectId($id),
                'pid' => $childPid,
                'sig' => $sig,
            ]);

            break;
        }

        $this->spawnMinimumWorkers();

        return $this;
    }

    public function count(): int
    {
        return count($this->forks);
    }

    public function cleanup(int $sig): void
    {
        $this->logger->debug(
            'received signal ['.$sig.']',
            ['category' => get_class($this)]
        );

        foreach ($this->getForks() as $id => $pid) {
            $this->logger->debug(
                'forward signal ['.$sig.'] to worker ['.$id.'] running with pid ['.$pid.']',
                ['category' => get_class($this)]
            );

            if ((int) $pid > 0) {
                @posix_kill((int) $pid, $sig);
            }
        }

        $this->exit();
    }

    protected function spawnInitialWorkers(): void
    {
        $this->logger->debug(
            'spawn initial ['.$this->min_children.'] workers',
            ['category' => get_class($this)]
        );

        if (
            self::PM_DYNAMIC === $this->pm
            || self::PM_STATIC === $this->pm
        ) {
            for ($i = $this->count(); $i < $this->min_children; ++$i) {
                $this->spawnWorker();
            }
        }
    }

    protected function spawnMinimumWorkers(): void
    {
        if (self::PM_ONDEMAND === $this->pm) {
            return;
        }

        $this->logger->debug(
            'verify that the minimum number ['.$this->min_children.'] of workers are running',
            ['category' => get_class($this)]
        );

        for ($i = $this->count(); $i < $this->min_children; ++$i) {
            $this->spawnWorker();
        }
    }

    protected function spawnWorker(?ObjectId $job = null)
    {
        $this->logger->debug(
            'spawn new worker',
            ['category' => get_class($this)]
        );

        $id = new ObjectId();
        $pid = pcntl_fork();

        if (-1 === $pid) {
            throw new SpawnForkException('failed to spawn new worker');
        }

        if (0 === $pid) {
            try {
                $worker = $this->factory->buildWorker($id);

                if (null === $job) {
                    $worker->processAll();
                } else {
                    $worker->processOne($job);
                }
            } catch (\Throwable $e) {
                $this->logger->error('worker process failed', [
                    'category' => get_class($this),
                    'worker' => (string) $id,
                    'exception' => $e,
                ]);

                exit(1);
            }

            exit(0);
        }

        $this->forks[(string) $id] = $pid;

        if (!$this->sendMessage(self::TYPE_WORKER_SPAWN, [
            '_id' => $id,
            'pid' => $pid,
        ])) {
            unset($this->forks[(string) $id]);

            @posix_kill($pid, SIGTERM);

            throw new SpawnForkException('failed to notify queue about spawned worker ['.$id.']');
        }

        $this->logger->debug(
            'spawned worker ['.$id.'] with pid ['.$pid.']',
            ['category' => get_class($this)]
        );

        return $pid;
    }

    protected function getForks(): array
    {
        return $this->forks;
    }

    protected function main(): void
    {
        while ($this->loop()) {
            if (count($this->onhold) > 0) {
                usleep(200);
                $this->processLocalQueue();
            }

            if (
                msg_receive(
                    $this->queue,
                    0,
                    $type,
                    16384,
                    $msg,
                    true,
                    MSG_IPC_NOWAIT | MSG_NOERROR
                )
            ) {
                $this->logger->debug(
                    'received systemv message type ['.$type.']',
                    ['category' => get_class($this)]
                );

                switch ($type) {
                    case self::TYPE_JOB:
                        $this->handleJob($msg);

                        break;
                    case self::TYPE_WORKER_SPAWN:
                    case self::TYPE_WORKER_KILL:
                        break;
                    case self::TYPE_WORKER_ORPHANED_JOB:
                        $this->handleOrphanedJob($msg);

                        break;
                    default:
                        $this->logger->warning(
                            'received unknown systemv message type ['.$type.']',
                            ['category' => get_class($this)]
                        );
                }
            }
        }
    }

    protected function handleJob(array $event): self
    {
        if (!isset($event['status'], $event['_id'])) {
            $this->logger->warning('received invalid job event', [
                'category' => get_class($this),
                'event' => $event,
            ]);

            return $this;
        }

        $this->logger->debug(
            'handle event ['.$event['status'].'] for job ['.$event['_id'].']',
            ['category' => get_class($this)]
        );

        switch ((int) $event['status']) {
            case JobInterface::STATUS_WAITING:
            case JobInterface::STATUS_POSTPONED:
                return $this->handleNewJob($event);
            case JobInterface::STATUS_PROCESSING:
                if (isset($event['worker'])) {
                    $this->job_map[(string) $event['worker']] = (string) $event['_id'];
                }

                return $this;
            case JobInterface::STATUS_DONE:
                $worker = array_search(
                    (string) $event['_id'],
                    $this->job_map,
                    true
                );

                if (false !== $worker) {
                    unset($this->job_map[$worker]);
                }

                return $this;
            case JobInterface::STATUS_CANCELED:
            case JobInterface::STATUS_FAILED:
            case JobInterface::STATUS_TIMEOUT:
                $worker = array_search(
                    (string) $event['_id'],
                    $this->job_map,
                    true
                );

                if (false === $worker) {
                    return $this;
                }

                $this->logger->debug(
                    'received failure event for job ['.$event['_id'].'] running on worker ['.$worker.']',
                    ['category' => get_class($this)]
                );

                if (isset($this->forks[$worker])) {
                    $this->logger->debug(
                        'found running worker ['.$worker.'] on this queue node, terminate it now',
                        ['category' => get_class($this)]
                    );

                    unset($this->job_map[$worker]);

                    @posix_kill($this->forks[$worker], SIGKILL);
                }

                if (JobInterface::STATUS_CANCELED === (int) $event['status']) {
                    $this->sendCancellationNotification($event);
                }

                return $this;
            default:
                $this->logger->warning(
                    'received event ['.$event['_id'].'] with unknown status ['.$event['status'].']',
                    ['category' => get_class($this)]
                );

                return $this;
        }
    }

    protected function sendCancellationNotification(array $event): void
    {
        if (null === $this->container) {
            return;
        }

        $jobId = (string) $event['_id'];

        if (in_array($jobId, $this->sent_notifications, true)) {
            return;
        }

        if (!isset($event['class'])) {
            return;
        }

        try {
            $instance = $this->container->get($event['class']);
        } catch (\Throwable $e) {
            $this->logger->error('could not resolve notification class', [
                'category' => get_class($this),
                'class' => $event['class'],
                'exception' => $e,
            ]);

            return;
        }

        if (!method_exists($instance, 'notification')) {
            $this->logger->info(
                'method notification() does not exist on instance',
                ['category' => get_class($this)]
            );

            return;
        }

        $job = $this->scheduler->getJob($event['_id'])->toArray();

        if (isset($job['notification_sent'])) {
            return;
        }

        try {
            $instance->notification(JobInterface::STATUS_CANCELED, $job);

            $this->db->{$this->scheduler->getJobQueue()}->updateOne(
                ['_id' => $event['_id']],
                ['$set' => ['notification_sent' => true]]
            );

            $this->sent_notifications[] = $jobId;
        } catch (\Throwable $e) {
            $this->logger->error('job cancellation notification failed', [
                'category' => get_class($this),
                'job' => $jobId,
                'exception' => $e,
            ]);
        }
    }

    protected function processLocalQueue(): self
    {
        foreach ($this->onhold as $id => $job) {
            $at = isset($job['options']['at'])
                ? (int) $job['options']['at']
                : 0;

            $forceSpawn = !empty($job['options']['force_spawn']);

            if ($at > time()) {
                continue;
            }

            if (!$forceSpawn && $this->count() >= $this->max_children) {
                continue;
            }

            unset($this->onhold[$id]);

            $this->logger->debug(
                'release job ['.$id.'] from local queue',
                ['category' => get_class($this)]
            );

            $this->spawnWorker($job['_id']);
        }

        return $this;
    }

    protected function handleNewJob(array $job): self
    {
        $options = isset($job['options']) && is_array($job['options'])
            ? $job['options']
            : [];

        $forceSpawn = !empty($options[Scheduler::OPTION_FORCE_SPAWN]);
        $at = isset($options[Scheduler::OPTION_AT])
            ? (int) $options[Scheduler::OPTION_AT]
            : 0;

        if ($forceSpawn) {
            if ($at > time()) {
                $this->onhold[(string) $job['_id']] = $job;

                return $this;
            }

            $this->spawnWorker($job['_id']);

            return $this;
        }

        if (self::PM_ONDEMAND === $this->pm) {
            if ($at > time() || $this->count() >= $this->max_children) {
                $this->onhold[(string) $job['_id']] = $job;

                return $this;
            }

            $this->spawnWorker($job['_id']);

            return $this;
        }

        if (
            $this->count() < $this->max_children
            && self::PM_DYNAMIC === $this->pm
        ) {
            $this->spawnWorker();
        }

        return $this;
    }

    protected function catchSignal(): self
    {
        pcntl_async_signals(true);

        pcntl_signal(SIGTERM, [$this, 'cleanup']);
        pcntl_signal(SIGINT, [$this, 'cleanup']);
        pcntl_signal(SIGCHLD, [$this, 'exitWorker']);

        return $this;
    }

    protected function handleOrphanedJob(array $job): void
    {
        if (!isset($job['worker'], $job['_id'])) {
            return;
        }

        $workerId = (string) $job['worker'];

        $this->logger->debug(
            'check if worker still exists ['.$workerId.']',
            ['category' => get_class($this)]
        );

        if (isset($this->forks[$workerId])) {
            $this->logger->debug(
                'worker with id ['.$workerId.'] still exists; job should be restarted automatically',
                ['category' => get_class($this)]
            );

            return;
        }

        $this->logger->warning(
            'worker with id ['.$workerId.'] does not exist anymore',
            ['category' => get_class($this)]
        );

        $collection = $this->db->{$this->scheduler->getJobQueue()};

        $rescheduled = $collection->find([
            'data.orphaned_parent_id' => $job['_id'],
        ])->toArray();

        if (count($rescheduled) > 0) {
            $this->logger->debug(
                'orphaned job ['.$job['_id'].'] is already rescheduled',
                ['category' => get_class($this)]
            );

            return;
        }

        if (!isset($job['data']) || !is_array($job['data'])) {
            $job['data'] = [];
        }

        $job['data']['orphaned_parent_id'] = $job['_id'];

        $interval = isset($job['options']['interval'])
            ? (int) $job['options']['interval']
            : 0;

        if ($interval > 0) {
            $job['options']['at'] = time() + $interval;

            $this->scheduler->addJob(
                $job['class'],
                $job['data'],
                $this->scheduler->setJobOptionsType($job['options'])
            );

            return;
        }

        if ($interval <= -1) {
            unset($job['options']['at']);

            $this->scheduler->addJob(
                $job['class'],
                $job['data'],
                $this->scheduler->setJobOptionsType($job['options'])
            );
        }
    }

    protected function sendMessage(int $type, array $message): bool
    {
        if (null === $this->queue) {
            return false;
        }

        $sent = @msg_send(
            $this->queue,
            $type,
            $message,
            true,
            true
        );

        if (!$sent) {
            $this->logger->error(
                'failed to send worker-manager message',
                [
                    'category' => get_class($this),
                    'type' => $type,
                    'message' => $message,
                ]
            );
        }

        return $sent;
    }
}
