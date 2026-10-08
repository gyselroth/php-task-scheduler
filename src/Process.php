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

class Process
{
    /**
     * Job.
     *
     * @var array
     */
    protected $job;

    /**
     * Scheduler.
     *
     * @var Scheduler
     */
    protected $scheduler;

    /**
     * Initialize process.
     */
    public function __construct(array $job, Scheduler $scheduler)
    {
        $this->job = $job;
        $this->scheduler = $scheduler;
    }

    /**
     * Replace process data.
     */
    public function replace(Process $process): self
    {
        $this->job = $process->toArray();

        return $this;
    }

    /**
     * To array.
     */
    public function toArray(): array
    {
        return $this->job;
    }

    /**
     * Get job options.
     */
    public function getOptions(): array
    {
        return isset($this->job['options']) && is_array($this->job['options'])
            ? $this->job['options']
            : [];
    }

    /**
     * Get class.
     */
    public function getClass(): string
    {
        return (string) ($this->job['class'] ?? '');
    }

    /**
     * Get job data.
     */
    public function getData()
    {
        return $this->job['data'] ?? null;
    }

    /**
     * Get ID.
     */
    public function getId(): ObjectId
    {
        if (!isset($this->job['_id']) || !($this->job['_id'] instanceof ObjectId)) {
            throw new \UnexpectedValueException('Process does not contain a valid ObjectId');
        }

        return $this->job['_id'];
    }

    /**
     * Get worker ID.
     */
    public function getWorker(): ?ObjectId
    {
        if (!isset($this->job['worker']) || $this->job['worker'] === null) {
            return null;
        }

        if (!$this->job['worker'] instanceof ObjectId) {
            throw new \UnexpectedValueException('Process does not contain a valid worker ObjectId');
        }

        return $this->job['worker'];
    }

    /**
     * Get current job progress.
     */
    public function getProgress(): float
    {
        return isset($this->job['progress'])
            ? (float) $this->job['progress']
            : 0.0;
    }

    /**
     * Wait for job being executed.
     */
    public function wait(): self
    {
        $this->scheduler->waitFor([$this], Scheduler::OPTION_THROW_EXCEPTION);

        return $this;
    }

    /**
     * Get status.
     */
    public function getStatus(): int
    {
        return isset($this->job['status'])
            ? (int) $this->job['status']
            : JobInterface::STATUS_WAITING;
    }
}
