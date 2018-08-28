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

    public function setUp()
    {
        $mongodb = new MockDatabase();
        $this->scheduler = new Scheduler($mongodb, $this->createMock(LoggerInterface::class));
        $this->queue = new Queue($this->scheduler, $mongodb, $this->createMock(WorkerFactoryInterface::class), $this->createMock(LoggerInterface::class));
    }

    public function testStartInitialDefaultWorkers()
    {
        $method = self::getMethod('catchSignal');
        $method->invokeArgs($this->queue, []);
        $method = self::getMethod('spawnInitialWorkers');
        $method->invokeArgs($this->queue, []);
        $method = self::getMethod('getForks');
        $forks = $method->invokeArgs($this->queue, []);
        $this->assertCount(1, $forks);
    }

    public function testStartTwoInitialWorkersViaConstructor()
    {
        $mongodb = new MockDatabase();
        $scheduler = new Scheduler($mongodb, $this->createMock(LoggerInterface::class));
        $queue = new Queue($scheduler, $mongodb, $this->createMock(WorkerFactoryInterface::class), $this->createMock(LoggerInterface::class), [
            Queue::OPTION_MIN_CHILDREN => 2,
        ]);

        $method = self::getMethod('catchSignal');
        $method->invokeArgs($this->queue, []);
        $method = self::getMethod('spawnInitialWorkers');
        $method->invokeArgs($queue, []);
        $method = self::getMethod('getForks');
        $forks = $method->invokeArgs($queue, []);
        $this->assertCount(2, $forks);
    }

    public function testTerminateStartedWorkers()
    {
        $this->queue->setOptions([
            Queue::OPTION_MIN_CHILDREN => 2,
            Queue::OPTION_MAX_CHILDREN => 4,
        ]);

        $method = self::getMethod('catchSignal');
        $method->invokeArgs($this->queue, []);
        $method = self::getMethod('spawnInitialWorkers');
        $method->invokeArgs($this->queue, []);
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

        $method = self::getMethod('catchSignal');
        $method->invokeArgs($this->queue, []);
        $method = self::getMethod('spawnInitialWorkers');
        $method->invokeArgs($this->queue, []);
        $method = self::getMethod('getForks');
        $forks = $method->invokeArgs($this->queue, []);
        $this->assertCount(2, $forks);
    }

    public function testOndemandWorkers()
    {
        $this->queue->setOptions([
            Queue::OPTION_PM => Queue::PM_ONDEMAND,
        ]);

        $method = self::getMethod('catchSignal');
        $method->invokeArgs($this->queue, []);
        $method = self::getMethod('spawnInitialWorkers');
        $method->invokeArgs($this->queue, []);
        $method = self::getMethod('getForks');
        $forks = $method->invokeArgs($this->queue, []);
        $this->assertCount(0, $forks);
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
