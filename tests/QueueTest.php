<?php

declare(strict_types=1);

/**
 * TaskScheduler
 *
 * @author      Raffael Sahli <sahli@gyselroth.net>
 * @copyright   Copryright (c) 2017-2018 gyselroth GmbH (https://gyselroth.com)
 * @license     MIT https://opensource.org/licenses/MIT
 */

namespace TaskScheduler\Testsuite;

use Helmich\MongoMock\MockDatabase;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use TaskScheduler\Exception\InvalidArgumentException;
use TaskScheduler\Queue;
use TaskScheduler\Scheduler;
use TaskScheduler\WorkerFactoryInterface;

class QueueTest extends TestCase
{
    protected $queue;
    protected $scheduler;
    protected $called = 0;

    public function setUp()
    {
        $mongodb = new MockDatabase();
        $this->scheduler = new Scheduler($mongodb, $this->createMock(LoggerInterface::class));

        $called = &$this->called;
        $this->queue = $this->getMockBuilder(Queue::class)
            ->setConstructorArgs([$this->scheduler, $mongodb, $this->createMock(WorkerFactoryInterface::class), $this->createMock(LoggerInterface::class)])
            ->setMethods(['loop'])
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
    }

    public function testStartInitialDefaultWorkers()
    {
        $this->queue->process();
        $this->assertSame(1, $this->queue->count());
    }

    public function testStartTwoInitialDynamicWorkers()
    {
        $this->queue->setOptions([
            Queue::OPTION_MIN_CHILDREN => 2,
        ]);

        $this->queue->process();
        $this->assertSame(2, $this->queue->count());
    }

    public function testTerminateStartedWorkers()
    {
        $this->queue->setOptions([
            Queue::OPTION_MIN_CHILDREN => 2,
            Queue::OPTION_MAX_CHILDREN => 4,
        ]);

        $this->queue->process();

        $method = self::getMethod('getForks');
        $forks = $method->invokeArgs($this->queue, []);
        $this->assertCount(2, $forks);

        foreach ($forks as $pid) {
            $this->assertNotSame(false, posix_getpgid($pid));
        }

        $method = self::getMethod('handleSignal');
        $method->invokeArgs($this->queue, [SIGKILL]);

        foreach ($forks as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertFalse(posix_getpgid($pid));
        }
    }

    public function testStartTwoStaticWorkers()
    {
        $this->queue->setOptions([
            Queue::OPTION_MIN_CHILDREN => 2,
            Queue::OPTION_PM => Queue::PM_STATIC,
        ]);

        $this->queue->process();
        $this->assertSame(2, $this->queue->count());
    }

    public function testOndemandWorkersNoJobs()
    {
        $this->queue->setOptions([
            Queue::OPTION_PM => Queue::PM_ONDEMAND,
        ]);

        $this->queue->process();
        $this->assertSame(0, $this->queue->count());
    }

    public function testOndemandWorkersOneJob()
    {
        $this->queue->setOptions([
            Queue::OPTION_PM => Queue::PM_ONDEMAND,
        ]);

        $this->scheduler->addJob('foo', 'bar');
        $this->queue->process();
        $this->assertSame(1, $this->queue->count());
    }

    public function testDynamicWorkersUntilMax()
    {
        $this->scheduler->addJob('foo', 'bar');
        $this->queue->process();
        $this->assertSame(2, $this->queue->count());

        $this->scheduler->addJob('foo', 'bar');
        $this->called = 0;
        $this->queue->process();
        $this->assertSame(2, $this->queue->count());
    }

    public function testDynamicForceStartWorkerIfIgnoreMaxChildren()
    {
        $this->scheduler->addJob('foo', 'foo', [
            Scheduler::OPTION_IGNORE_MAX_CHILDREN => true,
        ]);

        $this->queue->process();
        $this->assertSame(2, $this->queue->count());

        $this->scheduler->addJob('foo', 'foo', [
            Scheduler::OPTION_IGNORE_MAX_CHILDREN => true,
        ]);

        $this->called = 0;
        $this->queue->process();
        $this->assertSame(3, $this->queue->count());
    }

    public function testMinChildrenGreaterThanMaxChildren()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->queue->setOptions([
            Queue::OPTION_MIN_CHILDREN => 3,
            Queue::OPTION_MAX_CHILDREN => 2,
        ]);
    }

    public function testNotExistingProcessHandlingOption()
    {
        $this->expectException(InvalidArgumentException::class);

        $this->queue->setOptions([
            Queue::OPTION_PM => 'foo',
        ]);
    }

    public function testMinChildrenInteger()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->queue->setOptions([
            Queue::OPTION_MIN_CHILDREN => 'foo',
        ]);
    }

    public function testMaxChildrenInteger()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->queue->setOptions([
            Queue::OPTION_MAX_CHILDREN => 'foo',
        ]);
    }

    public function testNotExistingOption()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->queue->setOptions([
            'bar' => 'foo',
        ]);
    }

    protected static function getProperty($name): ReflectionProperty
    {
        $class = new ReflectionClass(Queue::class);
        $property = $class->getProperty($name);
        $property->setAccessible(true);

        return $property;
    }

    protected static function getMethod($name): ReflectionMethod
    {
        $class = new ReflectionClass(Queue::class);
        $method = $class->getMethod($name);
        $method->setAccessible(true);

        return $method;
    }
}
