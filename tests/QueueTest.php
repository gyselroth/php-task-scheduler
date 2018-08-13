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

use Helmich\MongoMock\MockCollection;
use Helmich\MongoMock\MockCursor;
use Helmich\MongoMock\MockDatabase;
use InvalidArgumentException;
use MongoDB\Driver\Exception\ConnectionException;
use MongoDB\Driver\Exception\RuntimeException;
use MongoDB\Driver\Exception\ServerException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
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

    public function testCursor()
    {
        $id = $this->scheduler->addJob('test', ['foo' => 'bar']);

        $job = $this->scheduler->getJob($id);
        $method = self::getMethod('getCursor');
        $cursor = $method->invokeArgs($this->queue, []);
        $this->assertSame(1, count($cursor->toArray()));
    }

    public function testCursorRetrieveNext()
    {
        $this->scheduler->addJob('test', ['foo' => 'bar']);
        $id = $this->scheduler->addJob('test', ['foo' => 'foobar']);
        $method = self::getMethod('getCursor');
        $cursor = $method->invokeArgs($this->queue, []);

        $method = self::getMethod('retrieveNextJob');
        $job = $method->invokeArgs($this->queue, [$cursor]);
        $this->assertSame($id, $cursor->current()['_id']);
    }

    /*public function testSignalHandlerAttached()
    {
        $method = self::getMethod('catchSignal');
        $method->invokeArgs($this->queue, []);
        $this->assertSame(pcntl_signal_get_handler(SIGTERM)[1], 'cleanup');
        $this->assertSame(pcntl_signal_get_handler(SIGINT)[1], 'cleanup');
    }*/

    /*public function testCleanupViaSigtermNoJob()
    {
        $method = self::getMethod('handleSignal');
        $method->invokeArgs($this->queue, [SIGTERM]);
    }*/

    /*public function testCleanupViaSigtermScheduleJob()
    {
        $id = $this->scheduler->addJob('test', ['foo' => 'bar']);
        $property = self::getProperty('current_job');
        $property->setValue($this->scheduler, $this->scheduler->getJob($id));

        $method = self::getMethod('handleSignal');
        $new = $method->invokeArgs($this->queue, [SIGTERM]);
        $this->assertNotSame($id, $new);
    }*/

    public function testCreateQueue()
    {
        $mongodb = new MockDatabase();
        $scheduler = new Scheduler($mongodb, $this->createMock(LoggerInterface::class));
        $queue = new Queue($scheduler, $mongodb, $this->createMock(WorkerFactoryInterface::class), $this->createMock(LoggerInterface::class));

        $method = self::getMethod('createQueue');
        $method->invokeArgs($queue, []);
        $this->assertSame('dummy', $mongodb->{$scheduler->getCollection()}->findOne([])['class']);
    }

    public function testCreateQueueAlreadyExistsNoException()
    {
        $mongodb = new MockDatabase();
        $queue = new Queue($this->createMock(Scheduler::class), $mongodb, $this->createMock(WorkerFactoryInterface::class), $this->createMock(LoggerInterface::class));
        $method = self::getMethod('createQueue');
        $method->invokeArgs($queue, []);

        $method = self::getMethod('createQueue');
        $method->invokeArgs($queue, []);
    }

    public function testCreateQueueRuntimeException()
    {
        $this->expectException(RuntimeException::class);
        $mongodb = $this->createMock(MockDatabase::class);
        $mongodb->expects($this->once())->method('createCollection')->will($this->throwException(new RuntimeException('error')));
        $this->expectException(RuntimeException::class);

        $queue = new Queue($this->createMock(Scheduler::class), $mongodb, $this->createMock(WorkerFactoryInterface::class), $this->createMock(LoggerInterface::class));
        $method = self::getMethod('createQueue');
        $method->invokeArgs($queue, []);
    }

    public function testCursorConnectionExceptionNotTailable()
    {
        $collection = $this->createMock(MockCollection::class);
        $exception = true;
        $collection->expects($this->once())->method('find')->will($this->returnCallback(function () use ($exception) {
            if (true === $exception) {
                $exception = false;

                if (class_exists(ServerException::class)) {
                    $this->throwException(new ServerException('not tailable', 2));
                } else {
                    $this->throwException(new ConnectionException('not tailable', 2));
                }
            }

            return new MockCursor();
        }));

        $mongodb = $this->createMock(MockDatabase::class);
        $mongodb->method('__get')->willReturn($collection);
        $queue = new Queue($this->createMock(Scheduler::class), $mongodb, $this->createMock(WorkerFactoryInterface::class), $this->createMock(LoggerInterface::class));
        $method = self::getMethod('getCursor');
        $method->invokeArgs($queue, [true]);
    }

    /*public function testCursorConnectionException()
    {
        $this->expectException(ConnectionException::class);
        $collection = $this->createMock(MockCollection::class);
        $collection->expects($this->once())->method('find')->will($this->throwException(new ConnectionException('error')));
        $mongodb = $this->createMock(MockDatabase::class);
        $mongodb->method('__get')->willReturn($collection);
        $queue = new Queue($this->createMock(Scheduler::class), $mongodb, $this->createMock(WorkerFactoryInterface::class), $this->createMock(LoggerInterface::class));
        $queue->processOnce();
    }*/

    public function testConvertQueue()
    {
        $method = self::getMethod('convertQueue');
        $method->invokeArgs($this->queue, []);
    }

    public function testStartInitialDefaultWorkers()
    {
        $method = self::getMethod('catchSignal');
        $method->invokeArgs($this->queue, []);
        $method = self::getMethod('startInitialWorkers');
        $method->invokeArgs($this->queue, []);
        $method = self::getMethod('getForks');
        $forks = $method->invokeArgs($this->queue, []);
        $this->assertCount(1, $forks);
    }

    public function testStartTwoInitialWorkersViaConstructor()
    {
        $mongodb = new MockDatabase();
        $scheduler = new Scheduler($mongodb, $this->createMock(LoggerInterface::class));
        $queue = new Queue($scheduler, $mongodb, $this->createMock(WorkerFactoryInterface::class), $this->createMock(LoggerInterface::class), null, [
            Queue::OPTION_MIN_CHILDREN => 2,
        ]);

        $method = self::getMethod('catchSignal');
        $method->invokeArgs($this->queue, []);
        $method = self::getMethod('startInitialWorkers');
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
        $method = self::getMethod('startInitialWorkers');
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
        $method = self::getMethod('startInitialWorkers');
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
        $method = self::getMethod('startInitialWorkers');
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
