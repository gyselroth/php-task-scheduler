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
}
