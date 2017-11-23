<?php

declare(strict_types=1);

/**
 * TaskScheduler
 *
 * @author      Raffael Sahli <sahli@gyselroth.net>
 * @copyright   Copryright (c) 2017 gyselroth GmbH (https://gyselroth.com)
 * @license     MIT https://opensource.org/licenses/MIT
 */

namespace TaskScheduler;

interface JobInterface
{
    /**
     * Get job data.
     *
     * @param mixed $data
     *
     * @return JobInterface
     */
    public function setData($data): self;

    /**
     * Get job data.
     *
     * @return mixed
     */
    public function getData(): array;

    /**
     * Start job.
     *
     * @return bool
     */
    public function start(): bool;
}
