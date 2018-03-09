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
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use TaskScheduler\Exception;
use TaskScheduler\Queue;
use TaskScheduler\Scheduler;
use TaskScheduler\Testsuite\Mock\ErrorJobMock;
use TaskScheduler\Testsuite\Mock\SuccessJobMock;

class QueueTest extends TestCase
{
    protected $queue;
    protected $scheduler;

    public function setUp()
    {
        $mongodb = new MockDatabase();
        $this->scheduler = new Scheduler($mongodb, $this->createMock(LoggerInterface::class));
        $this->queue = new Queue($this->scheduler, $mongodb, $this->createMock(LoggerInterface::class));
    }

    public function testExecuteJobInvalidJobClass()
    {
        $this->expectException(Exception\InvalidJob::class);
        $id = $this->scheduler->addJob('test', ['foo' => 'bar']);
        $job = $this->scheduler->getJob($id);

        $method = self::getMethod('executeJob');
        $method->invokeArgs($this->queue, [$job]);
    }

    public function testExecuteSuccessfulJob()
    {
        $id = $this->scheduler->addJob(SuccessJobMock::class, ['foo' => 'bar']);
        $job = $this->scheduler->getJob($id);

        $method = self::getMethod('executeJob');
        $method->invokeArgs($this->queue, [$job]);
        $job = $this->scheduler->getJob($id);
        $this->assertSame(Queue::STATUS_DONE, $job['status']);
    }

    public function testExecuteErrorJob()
    {
        $this->expectException(\Exception::class);
        $id = $this->scheduler->addJob(ErrorJobMock::class, ['foo' => 'bar']);
        $job = $this->scheduler->getJob($id);

        $method = self::getMethod('executeJob');
        $method->invokeArgs($this->queue, [$job]);
    }

    public function testProcessSuccessfulJob()
    {
        $id = $this->scheduler->addJob(SuccessJobMock::class, ['foo' => 'bar']);
        $job = $this->scheduler->getJob($id);

        $method = self::getMethod('processJob');
        $method->invokeArgs($this->queue, [$job]);
        $job = $this->scheduler->getJob($id);
        $this->assertSame(Queue::STATUS_DONE, $job['status']);
    }

    public function testProcessErrorJob()
    {
        $id = $this->scheduler->addJob(ErrorJobMock::class, ['foo' => 'bar']);
        $job = $this->scheduler->getJob($id);

        $method = self::getMethod('processJob');
        $method->invokeArgs($this->queue, [$job]);
        $job = $this->scheduler->getJob($id);
        $this->assertSame(Queue::STATUS_FAILED, $job['status']);
    }

    public function testProcessPostponedJob()
    {
        $id = $this->scheduler->addJob(SuccessJobMock::class, ['foo' => 'bar'], [
            Scheduler::OPTION_AT => time() + 60,
        ]);
        $job = $this->scheduler->getJob($id);

        $method = self::getMethod('processJob');
        $method->invokeArgs($this->queue, [$job]);
        $job = $this->scheduler->getJob($id);
        $this->assertSame(Queue::STATUS_POSTPONED, $job['status']);
    }

    public function testUpdateJob()
    {
        $id = $this->scheduler->addJob('test', ['foo' => 'bar']);
        $job = $this->scheduler->getJob($id);

        $method = self::getMethod('updateJob');
        $method->invokeArgs($this->queue, [$id, Queue::STATUS_PROCESSING]);
        $job = $this->scheduler->getJob($id);
        $this->assertSame(Queue::STATUS_PROCESSING, $job['status']);
    }

    public function testProcessLocalQueueWithPostponedJobInFuture()
    {
        $id = $this->scheduler->addJob('test', ['foo' => 'bar'], [
            Scheduler::OPTION_AT => time() + 10,
        ]);

        $method = self::getMethod('updateJob');
        $method->invokeArgs($this->queue, [$id, Queue::STATUS_POSTPONED]);
        $job = $this->scheduler->getJob($id);

        $queue = self::getProperty('queue');
        $queue->setValue($this->queue, [$job]);

        $method = self::getMethod('processLocalQueue');
        $method->invokeArgs($this->queue, []);

        $queue = self::getProperty('queue');
        $queue = $queue->getValue($this->queue);

        $this->assertSame(1, count($queue));
        $this->assertSame(Queue::STATUS_POSTPONED, $queue[0]['status']);
    }

    public function testProcessLocalQueueWithPostponedJobNow()
    {
        $id = $this->scheduler->addJob('test', ['foo' => 'bar'], [
            Scheduler::OPTION_AT => time(),
        ]);

        $method = self::getMethod('updateJob');
        $method->invokeArgs($this->queue, [$id, Queue::STATUS_POSTPONED]);
        $job = $this->scheduler->getJob($id);

        $queue = self::getProperty('queue');
        $queue->setValue($this->queue, [$job]);

        $method = self::getMethod('processLocalQueue');
        $method->invokeArgs($this->queue, []);

        $queue = self::getProperty('queue');
        $queue = $queue->getValue($this->queue);

        $this->assertSame(0, count($queue));
    }

    public function testProcessLocalQueueWithPostponedJobFromPast()
    {
        $id = $this->scheduler->addJob('test', ['foo' => 'bar'], [
            Scheduler::OPTION_AT => time() - 10,
        ]);

        $method = self::getMethod('updateJob');
        $method->invokeArgs($this->queue, [$id, Queue::STATUS_POSTPONED]);
        $job = $this->scheduler->getJob($id);

        $queue = self::getProperty('queue');
        $queue->setValue($this->queue, [$job]);

        $method = self::getMethod('processLocalQueue');
        $method->invokeArgs($this->queue, []);

        $queue = self::getProperty('queue');
        $queue = $queue->getValue($this->queue);

        $this->assertSame(0, count($queue));
    }

    public function testProcessErrorJobRetry()
    {
        $id = $this->scheduler->addJob(ErrorJobMock::class, ['foo' => 'bar'], [
            Scheduler::OPTION_RETRY => 1,
        ]);

        $job = $this->scheduler->getJob($id);
        $method = self::getMethod('processJob');
        $retry_id = $method->invokeArgs($this->queue, [$job]);
        $retry_job = $this->scheduler->getJob($retry_id);

        $this->assertSame(Queue::STATUS_WAITING, $retry_job['status']);
        $this->assertSame(0, $retry_job['retry']);
    }

    public function testProcessJobInterval()
    {
        $id = $this->scheduler->addJob(SuccessJobMock::class, ['foo' => 'bar'], [
            Scheduler::OPTION_INTERVAL => 100,
        ]);

        $job = $this->scheduler->getJob($id);
        $method = self::getMethod('processJob');
        $interval_id = $method->invokeArgs($this->queue, [$job]);
        $job = $this->scheduler->getJob($id);
        $interval_job = $this->scheduler->getJob($interval_id);

        $this->assertSame(Queue::STATUS_DONE, $job['status']);
        $this->assertSame(Queue::STATUS_WAITING, $interval_job['status']);
        $this->assertSame(100, $interval_job['interval']);
        $this->assertTrue((int) $interval_job['at']->toDateTime()->format('U') > time());
    }

    public function testCollectJob()
    {
        $id = $this->scheduler->addJob('test', ['foo' => 'bar']);

        $job = $this->scheduler->getJob($id);
        $method = self::getMethod('collectJob');
        $result = $method->invokeArgs($this->queue, [$job['_id'], Queue::STATUS_PROCESSING, Queue::STATUS_WAITING]);
        $this->assertTrue($result);
    }

    public function testCollectAlreadyCollectedJob()
    {
        $id = $this->scheduler->addJob('test', ['foo' => 'bar']);

        $job = $this->scheduler->getJob($id);
        $method = self::getMethod('collectJob');
        $method->invokeArgs($this->queue, [$job['_id'], Queue::STATUS_PROCESSING, Queue::STATUS_WAITING]);
        $result = $method->invokeArgs($this->queue, [$job['_id'], Queue::STATUS_PROCESSING, Queue::STATUS_WAITING]);

        $this->assertFalse($result);
    }

    public function testCursor()
    {
        $id = $this->scheduler->addJob('test', ['foo' => 'bar']);

        $job = $this->scheduler->getJob($id);
        $method = self::getMethod('getCursor');
        $cursor = $method->invokeArgs($this->queue, []);
        $this->assertSame(1, count($cursor->toArray()));
    }

    public function testCursorEmpty()
    {
        $id = $this->scheduler->addJob('test', ['foo' => 'bar']);
        $method = self::getMethod('updateJob');
        $method->invokeArgs($this->queue, [$id, Queue::STATUS_DONE]);

        $method = self::getMethod('getCursor');
        $cursor = $method->invokeArgs($this->queue, []);
        $this->assertSame(0, count($cursor->toArray()));
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

    public function testStartOnce()
    {
        $id = $this->scheduler->addJob('test', ['foo' => 'bar']);
        $this->queue->processOnce();
        $job = $this->scheduler->getJob($id);
        $this->assertSame(Queue::STATUS_FAILED, $job['status']);
    }

    public function testExecuteViaContainer()
    {
        $mongodb = new MockDatabase();

        $stub_container = $this->getMockBuilder(ContainerInterface::class)
            ->getMock();
        $stub_container->method('get')
            ->willReturn(new SuccessJobMock());

        $scheduler = new Scheduler($mongodb, $this->createMock(LoggerInterface::class));
        $this->queue = new Queue($scheduler, $mongodb, $this->createMock(LoggerInterface::class), $stub_container);

        $id = $scheduler->addJob(SuccessJobMock::class, ['foo' => 'bar']);
        $job = $scheduler->getJob($id);
        $method = self::getMethod('executeJob');
        $method->invokeArgs($this->queue, [$job]);
        $job = $scheduler->getJob($id);
        $this->assertSame(Queue::STATUS_DONE, $job['status']);
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
