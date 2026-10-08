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

interface JobInterface
{
    /**
     * Job status.
     */
    public const STATUS_WAITING = 0;
    public const STATUS_POSTPONED = 1;
    public const STATUS_PROCESSING = 2;
    public const STATUS_DONE = 3;
    public const STATUS_FAILED = 4;
    public const STATUS_CANCELED = 5;
    public const STATUS_TIMEOUT = 6;
    public const STATUS_MAP = [
        self::STATUS_WAITING => 'waiting',
        self::STATUS_POSTPONED => 'postponed',
        self::STATUS_PROCESSING => 'processing',
        self::STATUS_DONE => 'done',
        self::STATUS_FAILED => 'failed',
        self::STATUS_CANCELED => 'canceled',
        self::STATUS_TIMEOUT => 'timeout',
    ];

    /**
     * Pending jobs.
     */
    public const PENDING_JOBS = [
        self::STATUS_WAITING,
        self::STATUS_POSTPONED,
        self::STATUS_PROCESSING,
    ];

    /**
     * Failed jobs.
     */
    public const FAILED_JOBS = [
        self::STATUS_FAILED,
        self::STATUS_CANCELED,
        self::STATUS_TIMEOUT,
    ];

    /**
     * Set job data.
     */
    public function setData($data): self;

    /**
     * Get job data.
     */
    public function getData();

    /**
     * Set ID.
     */
    public function setId(ObjectId $id): self;

    /**
     * Get ID.
     */
    public function getId(): ObjectId;

    /**
     * Set scheduler.
     */
    public function setScheduler(Scheduler $scheduler): self;

    /**
     * Update job progress.
     */
    public function updateProgress(float $progress = 0): self;

    /**
     * Start job.
     */
    public function start(): bool;
}
