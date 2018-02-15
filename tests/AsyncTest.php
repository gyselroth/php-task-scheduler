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
use MongoDB\BSON\ObjectId;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use TaskScheduler\Async;
use TaskScheduler\Exception;
use TaskScheduler\Testsuite\Mock\ErrorJobMock;
use TaskScheduler\Testsuite\Mock\SuccessJobMock;

class AsyncTest extends TestCase
{
    protected $async;

    public function setUp()
    {
        $mongodb = new MockDatabase();
        $this->async = new Async($mongodb, $this->createMock(LoggerInterface::class));
    }

    public function testAddJob()
    {
        $id = $this->async->addJob('test', ['foo' => 'bar']);
        $this->assertInstanceOf(ObjectId::class, $id);
    }

    public function testNewJobStatus()
    {
        $id = $this->async->addJob('test', ['foo' => 'bar']);
        $job = $this->async->getJob($id);
        $this->assertSame($job['status'], Async::STATUS_WAITING);
    }

    public function testGetJob()
    {
        $id = $this->async->addJob('test', ['foo' => 'bar']);
        $job = $this->async->getJob($id);
        $this->assertSame($id, $job['_id']);
    }

    public function testGetJobs()
    {
        $id = $this->async->addJob('test', ['foo' => 'bar']);
        $jobs = $this->async->getJobs();
        $this->assertSame($jobs->toArray()[0]['_id'], $id);
        $this->assertTrue(is_array($jobs->toArray()[0]));
    }

    public function testGetClosedJobsWhenNoClosedJobsExist()
    {
        $id = $this->async->addJob('test', ['foo' => 'bar']);
        $jobs = $this->async->getJobs([Async::STATUS_DONE]);
        $this->assertSame(0, count($jobs->toArray()));
    }

    public function testGetInexistingJob()
    {
        $this->expectException(Exception::class);
        $job = $this->async->getJob(new ObjectId());
    }

    public function testCancelJob()
    {
        $id = $this->async->addJob('test', ['foo' => 'bar']);
        $this->async->cancelJob($id);
        $job = $this->async->getJob($id);
        $this->assertSame(Async::STATUS_CANCELED, $job['status']);
    }

    public function testAddJobAdvanced()
    {
        $id = $this->async->addJob('test', ['foo' => 'bar'], [
            Async::OPTION_RETRY => 10,
            Async::OPTION_INTERVAL => 60,
            Async::OPTION_RETRY_INTERVAL => 10,
        ]);

        $job = $this->async->getJob($id);
        $this->assertSame(10, $job[Async::OPTION_RETRY]);
        $this->assertSame(60, $job[Async::OPTION_INTERVAL]);
        $this->assertSame(10, $job[Async::OPTION_RETRY_INTERVAL]);
    }

    public function testAddJobAdvancedInvalidValue()
    {
        $this->expectException(Exception::class);
        $id = $this->async->addJob('test', ['foo' => 'bar'], [
            Async::OPTION_RETRY => '10',
            Async::OPTION_INTERVAL => 60,
            Async::OPTION_RETRY_INTERVAL => 10,
        ]);
    }

    public function testAddJobOnlyOnce()
    {
        $data = uniqid();
        $first = $this->async->addJobOnce('test', $data);
        $seccond = $this->async->addJobOnce('test', $data);
        $this->assertSame($first, $seccond);
        $jobs = $this->async->getJobs();
        $this->assertSame(1, count($jobs->toArray()));
    }

    public function testExecuteJobInvalidJobClass()
    {
        $this->expectException(Exception::class);
        $id = $this->async->addJob('test', ['foo' => 'bar']);
        $job = $this->async->getJob($id);

        $method = self::getMethod('executeJob');
        $method->invokeArgs($this->async, [$job]);
    }

    public function testExecuteSuccessfulJob()
    {
        $id = $this->async->addJob(SuccessJobMock::class, ['foo' => 'bar']);
        $job = $this->async->getJob($id);

        $method = self::getMethod('executeJob');
        $method->invokeArgs($this->async, [$job]);
        $job = $this->async->getJob($id);
        $this->assertSame(Async::STATUS_DONE, $job['status']);
    }

    public function testExecuteErrorJob()
    {
        $this->expectException(\Exception::class);
        $id = $this->async->addJob(ErrorJobMock::class, ['foo' => 'bar']);
        $job = $this->async->getJob($id);

        $method = self::getMethod('executeJob');
        $method->invokeArgs($this->async, [$job]);
    }

    public function testProcessSuccessfulJob()
    {
        $id = $this->async->addJob(SuccessJobMock::class, ['foo' => 'bar']);
        $job = $this->async->getJob($id);

        $method = self::getMethod('processJob');
        $method->invokeArgs($this->async, [$job]);
        $job = $this->async->getJob($id);
        $this->assertSame(Async::STATUS_DONE, $job['status']);
    }

    public function testProcessErrorJob()
    {
        $id = $this->async->addJob(ErrorJobMock::class, ['foo' => 'bar']);
        $job = $this->async->getJob($id);

        $method = self::getMethod('processJob');
        $method->invokeArgs($this->async, [$job]);
        $job = $this->async->getJob($id);
        $this->assertSame(Async::STATUS_FAILED, $job['status']);
    }

    public function testProcessPostponedJob()
    {
        $id = $this->async->addJob(SuccessJobMock::class, ['foo' => 'bar'], [
            Async::OPTION_AT => time() + 60,
        ]);
        $job = $this->async->getJob($id);

        $method = self::getMethod('processJob');
        $method->invokeArgs($this->async, [$job]);
        $job = $this->async->getJob($id);
        $this->assertSame(Async::STATUS_POSTPONED, $job['status']);
    }

    public function testUpdateJob()
    {
        $id = $this->async->addJob('test', ['foo' => 'bar']);
        $job = $this->async->getJob($id);

        $method = self::getMethod('updateJob');
        $method->invokeArgs($this->async, [$id, Async::STATUS_PROCESSING]);
        $job = $this->async->getJob($id);
        $this->assertSame(Async::STATUS_PROCESSING, $job['status']);
    }

    public function testProcessLocalQueueWithPostponedJobInFuture()
    {
        $id = $this->async->addJob('test', ['foo' => 'bar'], [
            Async::OPTION_AT => time() + 10,
        ]);

        $method = self::getMethod('updateJob');
        $method->invokeArgs($this->async, [$id, Async::STATUS_POSTPONED]);
        $job = $this->async->getJob($id);

        $queue = self::getProperty('queue');
        $queue->setValue($this->async, [$job]);

        $method = self::getMethod('processLocalQueue');
        $method->invokeArgs($this->async, []);

        $queue = self::getProperty('queue');
        $queue = $queue->getValue($this->async);

        $this->assertSame(1, count($queue));
        $this->assertSame(Async::STATUS_POSTPONED, $queue[0]['status']);
    }

    public function testProcessLocalQueueWithPostponedJobNow()
    {
        $id = $this->async->addJob('test', ['foo' => 'bar'], [
            Async::OPTION_AT => time(),
        ]);

        $method = self::getMethod('updateJob');
        $method->invokeArgs($this->async, [$id, Async::STATUS_POSTPONED]);
        $job = $this->async->getJob($id);

        $queue = self::getProperty('queue');
        $queue->setValue($this->async, [$job]);

        $method = self::getMethod('processLocalQueue');
        $method->invokeArgs($this->async, []);

        $queue = self::getProperty('queue');
        $queue = $queue->getValue($this->async);

        $this->assertSame(0, count($queue));
    }

    public function testProcessLocalQueueWithPostponedJobFromPast()
    {
        $id = $this->async->addJob('test', ['foo' => 'bar'], [
            Async::OPTION_AT => time() - 10,
        ]);

        $method = self::getMethod('updateJob');
        $method->invokeArgs($this->async, [$id, Async::STATUS_POSTPONED]);
        $job = $this->async->getJob($id);

        $queue = self::getProperty('queue');
        $queue->setValue($this->async, [$job]);

        $method = self::getMethod('processLocalQueue');
        $method->invokeArgs($this->async, []);

        $queue = self::getProperty('queue');
        $queue = $queue->getValue($this->async);

        $this->assertSame(0, count($queue));
    }

    public function testProcessErrorJobRetry()
    {
        $id = $this->async->addJob(ErrorJobMock::class, ['foo' => 'bar'], [
            Async::OPTION_RETRY => 1,
        ]);

        $job = $this->async->getJob($id);
        $method = self::getMethod('processJob');
        $retry_id = $method->invokeArgs($this->async, [$job]);
        $retry_job = $this->async->getJob($retry_id);

        $this->assertSame(Async::STATUS_WAITING, $retry_job['status']);
        $this->assertSame(0, $retry_job['retry']);
    }

    public function testProcessJobInterval()
    {
        $id = $this->async->addJob(SuccessJobMock::class, ['foo' => 'bar'], [
            Async::OPTION_INTERVAL => 100,
        ]);

        $job = $this->async->getJob($id);
        $method = self::getMethod('processJob');
        $interval_id = $method->invokeArgs($this->async, [$job]);
        $job = $this->async->getJob($id);
        $interval_job = $this->async->getJob($interval_id);

        $this->assertSame(Async::STATUS_DONE, $job['status']);
        $this->assertSame(Async::STATUS_WAITING, $interval_job['status']);
        $this->assertSame(100, $interval_job['interval']);
        $this->assertTrue((int) $interval_job['at']->toDateTime()->format('U') > time());
    }

    public function testCollectJob()
    {
        $id = $this->async->addJob('test', ['foo' => 'bar']);

        $job = $this->async->getJob($id);
        $method = self::getMethod('collectJob');
        $result = $method->invokeArgs($this->async, [$job['_id'], Async::STATUS_PROCESSING, Async::STATUS_WAITING]);
        $this->assertTrue($result);
    }

    public function testCollectAlreadyCollectedJob()
    {
        $id = $this->async->addJob('test', ['foo' => 'bar']);

        $job = $this->async->getJob($id);
        $method = self::getMethod('collectJob');
        $method->invokeArgs($this->async, [$job['_id'], Async::STATUS_PROCESSING, Async::STATUS_WAITING]);
        $result = $method->invokeArgs($this->async, [$job['_id'], Async::STATUS_PROCESSING, Async::STATUS_WAITING]);

        $this->assertFalse($result);
    }

    public function testCursor()
    {
        $id = $this->async->addJob('test', ['foo' => 'bar']);

        $job = $this->async->getJob($id);
        $method = self::getMethod('getCursor');
        $cursor = $method->invokeArgs($this->async, []);
        $this->assertSame(1, count($cursor->toArray()));
    }

    public function testCursorEmpty()
    {
        $id = $this->async->addJob('test', ['foo' => 'bar']);
        $method = self::getMethod('updateJob');
        $method->invokeArgs($this->async, [$id, Async::STATUS_DONE]);

        $method = self::getMethod('getCursor');
        $cursor = $method->invokeArgs($this->async, []);
        $this->assertSame(0, count($cursor->toArray()));
    }

    public function testCursorRetrieveNext()
    {
        $this->async->addJob('test', ['foo' => 'bar']);
        $id = $this->async->addJob('test', ['foo' => 'foobar']);
        $method = self::getMethod('getCursor');
        $cursor = $method->invokeArgs($this->async, []);

        $method = self::getMethod('retrieveNextJob');
        $job = $method->invokeArgs($this->async, [$cursor]);
        $this->assertSame($id, $cursor->current()['_id']);
    }

    public function testStartOnce()
    {
        $id = $this->async->addJob('test', ['foo' => 'bar']);
        $this->async->startOnce();
        $job = $this->async->getJob($id);
        $this->assertSame(Async::STATUS_FAILED, $job['status']);
    }

    public function testInitDefaultOptions()
    {
        $mongodb = new MockDatabase();
        $this->async = new Async($mongodb, $this->createMock(LoggerInterface::class), null, [
            'collection_name' => 'jobs',
            'default_retry' => 1,
            'default_at' => 1000000,
            'default_retry_interval' => 1,
            'default_interval' => 300,
            'queue_size' => 10,
        ]);

        $id = $this->async->addJob('test', ['foo' => 'bar']);
        $job = $this->async->getJob($id);
        $this->assertSame(1, $job['retry']);
        $this->assertSame(1000000, (int) $job['at']->toDateTime()->format('U'));
        $this->assertSame(1, $job['retry_interval']);
        $this->assertSame(300, $job['interval']);
    }

    public function testExecuteViaContainer()
    {
        $mongodb = new MockDatabase();

        $stub_container = $this->getMockBuilder(ContainerInterface::class)
            ->getMock();
        $stub_container->method('get')
            ->willReturn(new SuccessJobMock());

        $this->async = new Async($mongodb, $this->createMock(LoggerInterface::class), $stub_container);

        $id = $this->async->addJob(SuccessJobMock::class, ['foo' => 'bar']);
        $job = $this->async->getJob($id);
        $method = self::getMethod('executeJob');
        $method->invokeArgs($this->async, [$job]);
        $job = $this->async->getJob($id);
        $this->assertSame(Async::STATUS_DONE, $job['status']);
    }

    public function testCreateQueue()
    {
        $this->async->createQueue();
    }

    protected static function getProperty($name): ReflectionProperty
    {
        $class = new ReflectionClass(Async::class);
        $property = $class->getProperty($name);
        $property->setAccessible(true);

        return $property;
    }

    protected static function getMethod($name): ReflectionMethod
    {
        $class = new ReflectionClass(Async::class);
        $method = $class->getMethod($name);
        $method->setAccessible(true);

        return $method;
    }
}
