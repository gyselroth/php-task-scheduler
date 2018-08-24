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

use MongoDB\BSON\ObjectId;

class Process
{
    /**
     * Job.
     *
     * @var array
     */
    protected $job;

    public function __construct(array $job, Scheduler $scheduler, MessageQueue $events)
    {
        $this->job = $job;
        $this->scheduler = $scheduler;
        $this->events = $events;
    }

    public function getOptions(): array
    {
        return $this->job;
    }

    public function getData()
    {
        return $this->job['data'];
    }

    public function getId(): ObjectId
    {
        return $this->job['_id'];
    }

    public function restart(): Process
    {
    }

    public function kill(): bool
    {
    }

    public function wait(): Process
    {
        $events = $this->events->getCursor([
            'job' => $this->getId(),
            'event' => ['$gte' => JobInterface::STATUS_DONE],
        ]);

        while (true) {
            if (null === $cursor->current()) {
                if ($cursor->getInnerIterator()->isDead()) {
                    $this->logger->error('event queue cursor is dead, is it a capped collection?', [
                        'category' => get_class($this),
                    ]);

                    $this->create();

                    return $this->wait();
                }

                $this->events->next($cursor);

                continue;
            }

            $event = $cursor->current();
            $this->next($cursor);

            $this->status = $event['status'];

            if (JobInterface::STATUS_FAILED === $this->status || JobInterface::STATUS_DONE === $this->status) {
                $this->result = unserialize($event['data']);
                if ($this->result instanceof Exception) {
                    throw $this->result;
                }
            }

            return $this;
        }
    }

    public function getResult()
    {
        return $this->result;
    }

    public function getStatus(): int
    {
        return $this->status;
    }
}
