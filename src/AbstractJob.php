<?php

declare(strict_types=1);

/**
 * TaskScheduler
 *
 * @author      Raffael Sahli <sahli@gyselroth.net>
 * @copyright   Copryright (c) 2017-2019 gyselroth GmbH (https://gyselroth.com)
 * @license     MIT https://opensource.org/licenses/MIT
 */

namespace TaskScheduler;

use MongoDB\BSON\ObjectId;

abstract class AbstractJob implements JobInterface
{
    /**
     * Data.
     *
     * @var mixed
     **/
    protected $data;

    /**
     * Scheduler
     *
     * @var Scheduler
     */
    protected $scheduler;

    /**
     * Job ID.
     *
     * @var ObjectId
     */
    protected $id;

    /**
     * {@inheritdoc}
     */
    public function setData($data): JobInterface
    {
        $this->data = $data;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * {@inheritdoc}
     */
    public function setId(ObjectId $id): JobInterface
    {
        $this->id = $id;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getId(): ObjectId
    {
        return $this->id;
    }

    /**
     * {@inheritdoc}
     */
    public function setScheduler(Scheduler $scheduler): JobInterface
    {
        $this->scheduler = $scheduler;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function updateProgress(float $progress=0): JobInterface
    {
        $this->scheduler->updateJobProgress($this, $progress);
        return $this;
    }
}
