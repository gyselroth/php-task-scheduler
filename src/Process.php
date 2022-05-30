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
        return $this->job['options'];
    }

    /**
     * Get class.
     */
    public function getClass(): string
    {
        return $this->job['class'];
    }

    /**
     * Get job data.
     */
    public function getData()
    {
        return $this->job['data'];
    }

    /**
     * Get ID.
     */
    public function getId(): ObjectId
    {
        return $this->job['_id'];
    }

    /**
     * Restart job.
     */
    public function getWorker(): ObjectId
    {
        return $this->job['worker'];
    }

    /**
     * Get current job progress.
     */
    public function getProgress(): float
    {
        return $this->job['progress'] ?? 0.0;
    }

    /**
     * Wait for job beeing executed.
     */
    public function wait(): Process
    {
        $this->scheduler->waitFor([$this], Scheduler::OPTION_THROW_EXCEPTION);

        return $this;
    }

    /**
     * Get status.
     */
    public function getStatus(): int
    {
        return (int) $this->job['status'];
    }
}
