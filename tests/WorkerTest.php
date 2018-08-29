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
use MongoDB\BSON\UTCDateTime;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use TaskScheduler\Exception\InvalidJobException;
use TaskScheduler\JobInterface;
use TaskScheduler\Scheduler;
use TaskScheduler\Testsuite\Mock\ErrorJobMock;
use TaskScheduler\Testsuite\Mock\SuccessJobMock;
use TaskScheduler\Worker;

class WorkerTest extends TestCase
{
    protected $worker;
    protected $scheduler;

    public function setUp()
    {
        $mongodb = new MockDatabase();
        $this->scheduler = new Scheduler($mongodb, $this->createMock(LoggerInterface::class));
        $this->worker = new Worker($this->scheduler, $mongodb, $this->createMock(LoggerInterface::class));
    }

    public function testExecuteJobInvalidJobClass()
    {
        $this->expectException(InvalidJobException::class);
        $job = $this->scheduler->addJob('test', ['foo' => 'bar'])->toArray();
        $method = self::getMethod('executeJob');
        $method->invokeArgs($this->worker, [$job]);
    }

    public function testExecuteSuccessfulJob()
    {
        $job = $this->scheduler->addJob(SuccessJobMock::class, ['foo' => 'bar'])->toArray();
        $start = new UTCDateTime();
        $method = self::getMethod('executeJob');
        $method->invokeArgs($this->worker, [$job]);
        $job = $this->scheduler->getJob($job['_id']);
        $this->assertTrue($job->toArray()['ended'] >= $start);
    }

    public function testExecuteSuccessfulJobWaitFor()
    {
        $job = $this->scheduler->addJob(SuccessJobMock::class, ['foo' => 'bar']);
        $start = new UTCDateTime();
        $method = self::getMethod('executeJob');
        $method->invokeArgs($this->worker, [$job->toArray()]);
        $job->wait();
        $this->assertSame(JobInterface::STATUS_DONE, $job->getStatus());
    }

    public function testExecuteErrorJob()
    {
        $this->expectException(\Exception::class);
        $job = $this->scheduler->addJob(ErrorJobMock::class, ['foo' => 'bar'])->toArray();

        $start = new UTCDateTime();
        $method = self::getMethod('executeJob');
        $method->invokeArgs($this->worker, [$job]);

        $job = $this->scheduler->getJob($job['_id']);
        $this->assertSame(JobInterface::STATUS_FAILED, $job['status']);
        $this->assertTrue($job['started'] >= $start);
        $this->assertTrue($job['ended'] >= $start);
    }

    public function testExecuteErrorJobWaitFor()
    {
        $this->expectException(\Exception::class);
        $job = $this->scheduler->addJob(ErrorJobMock::class, ['foo' => 'bar']);
        $start = new UTCDateTime();
        $method = self::getMethod('processJob');
        $method->invokeArgs($this->worker, [$job->toArray()]);
        $job->wait();
        $this->assertSame(JobInterface::STATUS_FAILED, $job->getStatus());
    }

    public function testProcessSuccessfulJob()
    {
        $job = $this->scheduler->addJob(SuccessJobMock::class, ['foo' => 'bar'])->toArray();
        $method = self::getMethod('processJob');
        $method->invokeArgs($this->worker, [$job]);
        $job = $this->scheduler->getJob($job['_id']);
        $this->assertSame(JobInterface::STATUS_DONE, $job->getStatus());
    }

    public function testProcessErrorJob()
    {
        $job = $this->scheduler->addJob(ErrorJobMock::class, ['foo' => 'bar'])->toArray();

        $method = self::getMethod('processJob');
        $method->invokeArgs($this->worker, [$job]);
        $job = $this->scheduler->getJob($job['_id']);
        $this->assertSame(JobInterface::STATUS_FAILED, $job->getStatus());
    }

    public function testProcessPostponedJob()
    {
        $job = $this->scheduler->addJob(SuccessJobMock::class, ['foo' => 'bar'], [
            Scheduler::OPTION_AT => time() + 60,
        ])->toArray();

        $method = self::getMethod('processJob');
        $method->invokeArgs($this->worker, [$job]);
        $job = $this->scheduler->getJob($job['_id']);
        $this->assertSame(JobInterface::STATUS_POSTPONED, $job->getStatus());
    }

    public function testUpdateJob()
    {
        $job = $this->scheduler->addJob('test', ['foo' => 'bar'])->toArray();
        $method = self::getMethod('updateJob');
        $method->invokeArgs($this->worker, [$job, JobInterface::STATUS_PROCESSING]);
        $job = $this->scheduler->getJob($job['_id']);
        $this->assertSame(JobInterface::STATUS_PROCESSING, $job->getStatus());
    }

    public function testTimeoutNoRunningJob()
    {
        $job = $this->scheduler->addJob(SuccessJobMock::class, ['foo' => 'bar'], [
            Scheduler::OPTION_TIMEOUT => 10,
        ])->toArray();

        $this->assertSame(null, $this->worker->timeout());
        $this->assertCount(1, iterator_to_array($this->scheduler->getJobs()));
    }

    public function testTimeoutJob()
    {
        $job = $this->scheduler->addJob(SuccessJobMock::class, ['foo' => 'bar'], [
            Scheduler::OPTION_TIMEOUT => 10,
        ])->toArray();

        $current = self::getProperty('current_job');
        $current->setValue($this->worker, $job);
        $called = false;

        pcntl_signal(SIGTERM, function () use (&$called) {
            $called = true;
        });

        $this->worker->timeout();

        $this->assertTrue($called);
        $job = $this->scheduler->getJob($job['_id']);
        $this->assertSame(JobInterface::STATUS_TIMEOUT, $job->getStatus());
    }

    public function testTimeoutJobWithInterval()
    {
        $job = $this->scheduler->addJob(SuccessJobMock::class, ['foo' => 'bar'], [
            Scheduler::OPTION_TIMEOUT => 10,
            Scheduler::OPTION_INTERVAL => 10,
        ])->toArray();

        $current = self::getProperty('current_job');
        $current->setValue($this->worker, $job);
        $new = $this->worker->timeout();
        $this->assertInstanceOf(ObjectId::class, $new);
        $this->assertNotSame($job['_id'], $new);
    }

    public function testTimeoutJobWithRetry()
    {
        $job = $this->scheduler->addJob(SuccessJobMock::class, ['foo' => 'bar'], [
            Scheduler::OPTION_TIMEOUT => 10,
            Scheduler::OPTION_RETRY => 2,
        ])->toArray();

        $current = self::getProperty('current_job');
        $current->setValue($this->worker, $job);
        $new = $this->worker->timeout();
        $new = $this->scheduler->getJob($new);
        $this->assertSame(1, $new->getOptions()['retry']);
        $this->assertNotSame($job['_id'], $new->getId());
    }

    public function testProcessLocalQueueWithPostponedJobInFuture()
    {
        $job = $this->scheduler->addJob('test', ['foo' => 'bar'], [
            Scheduler::OPTION_AT => time() + 10,
        ])->toArray();

        $method = self::getMethod('updateJob');
        $method->invokeArgs($this->worker, [$job, JobInterface::STATUS_POSTPONED]);
        $job = $this->scheduler->getJob($job['_id'])->toArray();

        $queue = self::getProperty('queue');
        $queue->setValue($this->worker, [$job]);

        $method = self::getMethod('processLocalQueue');
        $method->invokeArgs($this->worker, []);

        $queue = self::getProperty('queue');
        $queue = $queue->getValue($this->worker);

        $this->assertSame(1, count($queue));
        $this->assertSame(JobInterface::STATUS_POSTPONED, $queue[0]['status']);
    }

    public function testProcessLocalQueueWithPostponedJobNow()
    {
        $job = $this->scheduler->addJob('test', ['foo' => 'bar'], [
            Scheduler::OPTION_AT => time(),
        ])->toArray();

        $method = self::getMethod('updateJob');
        $method->invokeArgs($this->worker, [$job, JobInterface::STATUS_POSTPONED]);
        $job = $this->scheduler->getJob($job['_id'])->toArray();

        $queue = self::getProperty('queue');
        $queue->setValue($this->worker, [$job]);

        $method = self::getMethod('processLocalQueue');
        $method->invokeArgs($this->worker, []);

        $queue = self::getProperty('queue');
        $queue = $queue->getValue($this->worker);

        $this->assertSame(0, count($queue));
    }

    public function testProcessLocalQueueWithPostponedJobFromPast()
    {
        $job = $this->scheduler->addJob('test', ['foo' => 'bar'], [
            Scheduler::OPTION_AT => time() - 10,
        ])->toArray();

        $method = self::getMethod('updateJob');
        $method->invokeArgs($this->worker, [$job, JobInterface::STATUS_POSTPONED]);
        $job = $this->scheduler->getJob($job['_id'])->toArray();

        $queue = self::getProperty('queue');
        $queue->setValue($this->worker, [$job]);

        $method = self::getMethod('processLocalQueue');
        $method->invokeArgs($this->worker, []);

        $queue = self::getProperty('queue');
        $queue = $queue->getValue($this->worker);

        $this->assertSame(0, count($queue));
    }

    public function testProcessErrorJobRetry()
    {
        $job = $this->scheduler->addJob(ErrorJobMock::class, ['foo' => 'bar'], [
            Scheduler::OPTION_RETRY => 1,
        ])->toArray();

        $method = self::getMethod('processJob');
        $retry_id = $method->invokeArgs($this->worker, [$job]);
        $retry_job = $this->scheduler->getJob($retry_id);

        $this->assertSame(JobInterface::STATUS_WAITING, $retry_job->getStatus());
        $this->assertSame(0, $retry_job->getOptions()['retry']);
    }

    public function testProcessJobInterval()
    {
        $job = $this->scheduler->addJob(SuccessJobMock::class, ['foo' => 'bar'], [
            Scheduler::OPTION_INTERVAL => 100,
        ])->toArray();

        $method = self::getMethod('processJob');
        $interval_id = $method->invokeArgs($this->worker, [$job]);
        $job = $this->scheduler->getJob($job['_id']);
        $interval_job = $this->scheduler->getJob($interval_id);

        $this->assertSame(JobInterface::STATUS_DONE, $job->getStatus());
        $this->assertSame(JobInterface::STATUS_WAITING, $interval_job->getStatus());
        $this->assertSame(100, $interval_job->getOptions()['interval']);
        $this->assertTrue((int) $interval_job->getOptions()['at']->toDateTime()->format('U') > time());
    }

    public function testCollectJob()
    {
        $job = $this->scheduler->addJob('test', ['foo' => 'bar'])->toArray();

        $start = new UTCDateTime();
        $method = self::getMethod('collectJob');
        $result = $method->invokeArgs($this->worker, [$job, JobInterface::STATUS_PROCESSING, JobInterface::STATUS_WAITING]);
        $this->assertTrue($result);
        $job = $this->scheduler->getJob($job['_id']);
        $this->assertSame(JobInterface::STATUS_PROCESSING, $job->getStatus());
        $this->assertTrue($job->toArray()['started'] >= $start);
    }

    public function testCollectAlreadyCollectedJob()
    {
        $job = $this->scheduler->addJob('test', ['foo' => 'bar'])->toArray();
        $method = self::getMethod('collectJob');
        $method->invokeArgs($this->worker, [$job, JobInterface::STATUS_PROCESSING, JobInterface::STATUS_WAITING]);
        $result = $method->invokeArgs($this->worker, [$job, JobInterface::STATUS_PROCESSING, JobInterface::STATUS_WAITING]);

        $this->assertFalse($result);
    }

    public function testExecuteViaContainer()
    {
        $mongodb = new MockDatabase();

        $stub_container = $this->getMockBuilder(ContainerInterface::class)
            ->getMock();
        $stub_container->method('get')
            ->willReturn(new SuccessJobMock());

        $scheduler = new Scheduler($mongodb, $this->createMock(LoggerInterface::class));
        $worker = new Worker($scheduler, $mongodb, $this->createMock(LoggerInterface::class), $stub_container);

        $job = $scheduler->addJob(SuccessJobMock::class, ['foo' => 'bar'])->toArray();
        $method = self::getMethod('executeJob');
        $method->invokeArgs($worker, [$job]);
        $job = $scheduler->getJob($job['_id']);
        $this->assertSame(JobInterface::STATUS_DONE, $job->getStatus());
    }

    public function testSignalHandlerAttached()
    {
        $method = self::getMethod('catchSignal');
        $method->invokeArgs($this->worker, []);
        $this->assertSame(pcntl_signal_get_handler(SIGTERM)[1], 'cleanup');
        $this->assertSame(pcntl_signal_get_handler(SIGINT)[1], 'cleanup');
    }

    public function testCleanupViaSigtermNoJob()
    {
        $method = self::getMethod('terminate');
        $method->invokeArgs($this->worker, [SIGTERM]);
    }

    public function testCleanupViaSigtermScheduleJob()
    {
        $job = $this->scheduler->addJob('test', ['foo' => 'bar']);
        $property = self::getProperty('current_job');
        $property->setValue($this->worker, $job->toArray());

        $method = self::getMethod('terminate');
        $new = $method->invokeArgs($this->worker, [SIGTERM]);
        $this->assertInstanceOf(ObjectId::class, $new);
        $this->assertNotSame($job->getId(), $new);
    }

    protected static function getProperty($name): ReflectionProperty
    {
        $class = new ReflectionClass(Worker::class);
        $property = $class->getProperty($name);
        $property->setAccessible(true);

        return $property;
    }

    protected static function getMethod($name): ReflectionMethod
    {
        $class = new ReflectionClass(Worker::class);
        $method = $class->getMethod($name);
        $method->setAccessible(true);

        return $method;
    }
}
