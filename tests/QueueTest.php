<?php

declare(strict_types=1);

/**
 * TaskScheduler
 *
 * @author      gyselroth™  (http://www.gyselroth.com)
 * @copyright   Copryright (c) 2017-2021 gyselroth GmbH (https://gyselroth.com)
 * @license     MIT https://opensource.org/licenses/MIT
 */

namespace TaskScheduler\Testsuite;

use Helmich\MongoMock\MockDatabase;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TaskScheduler\Queue;
use TaskScheduler\Scheduler;
use TaskScheduler\WorkerFactoryInterface;

class QueueTest extends TestCase
{
    protected $queue;
    protected $scheduler;
    protected $called = 0;
    protected $mongodb;

    public function setUp(): void
    {
        $this->mongodb = new MockDatabase();
        $this->scheduler = new Scheduler($this->mongodb, $this->createMock(LoggerInterface::class));

        $called = &$this->called;
        $this->queue = $this->getMockBuilder(Queue::class)
            ->setConstructorArgs([$this->scheduler, $this->mongodb, $this->createMock(WorkerFactoryInterface::class), $this->createMock(LoggerInterface::class)])
            ->setMethods(['loop', 'exit'])
            ->getMock();
        $this->queue->method('loop')
            ->will(
                $this->returnCallback(function () use (&$called) {
                    if (0 === $called) {
                        ++$called;

                        return true;
                    }

                    return false;
                })
            );
        $this->queue->method('exit')
            ->will(
                $this->returnCallback(function () {
                    return true;
                })
            );
    }

    public function testProcess()
    {
        $this->assertNull($this->queue->process());
    }
}
