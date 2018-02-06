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

abstract class AbstractJob implements JobInterface
{
    /**
     * Data.
     *
     * @var mixed
     **/
    protected $data;

    /**
     * Get data.
     *
     * @param mixed $data
     *
     * @return JobInterface
     */
    public function setData($data): JobInterface
    {
        $this->data = $data;

        return $this;
    }

    /**
     * Get data.
     *
     * @return mixed
     */
    public function getData()
    {
        return $this->data;
    }
}
