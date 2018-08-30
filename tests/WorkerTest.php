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
    protected $mongodb;
    protected $called = 0;

    public function setUp()
    {
        $this->mongodb = new MockDatabase();
        $this->scheduler = new Scheduler($this->mongodb, $this->createMock(LoggerInterface::class));

        $called = &$this->called;
        $this->worker = $this->getMockBuilder(Worker::class)
            ->setConstructorArgs([new ObjectId(), $this->scheduler, $this->mongodb, $this->createMock(LoggerInterface::class)])
            ->setMethods(['loop'])
            ->getMock();
        $this->worker->method('loop')
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

    public function testStartWorkerNoJob()
    {
        $this->worker->start();
    }

    public function testStartWorkerOneSuccessJob()
    {
        $start = new UTCDateTime();
        $job = $this->scheduler->addJob(SuccessJobMock::class, ['foo' => 'bar']);
        $this->assertSame(JobInterface::STATUS_WAITING, $job->getStatus());
        $this->worker->start();
        $job = $this->scheduler->getJob($job->getId());
        $this->assertSame(JobInterface::STATUS_DONE, $job->getStatus());
        $this->assertTrue($job->toArray()['ended'] >= $start);
    }

    public function testStartWorkerOneErrorJob()
    {
        $start = new UTCDateTime();
        $job = $this->scheduler->addJob(ErrorJobMock::class, ['foo' => 'bar']);
        $this->assertSame(JobInterface::STATUS_WAITING, $job->getStatus());
        $this->worker->start();
        $job = $this->scheduler->getJob($job->getId());
        $this->assertSame(JobInterface::STATUS_FAILED, $job->getStatus());
        $this->assertTrue($job->toArray()['ended'] >= $start);
    }

    public function testExecuteJobInvalidJobClass()
    {
        $this->expectException(InvalidJobException::class);
        $job = $this->scheduler->addJob('test', ['foo' => 'bar'])->toArray();
        $method = self::getMethod('executeJob');
        $method->invokeArgs($this->worker, [$job]);
    }

    public function testExecuteSuccessfulJobWaitFor()
    {
        $job = $this->scheduler->addJob(SuccessJobMock::class, ['foo' => 'bar']);
        $this->assertSame(JobInterface::STATUS_WAITING, $job->getStatus());
        $this->worker->start();
        $job->wait();
        $this->assertSame(JobInterface::STATUS_DONE, $job->getStatus());
    }

    public function testExecuteErrorJobWaitFor()
    {
        $this->expectException(\Exception::class);
        $job = $this->scheduler->addJob(ErrorJobMock::class, ['foo' => 'bar']);
        $this->assertSame(JobInterface::STATUS_WAITING, $job->getStatus());
        $this->worker->start();
        $job->wait();
        $this->assertSame(JobInterface::STATUS_FAILED, $job->getStatus());
    }

    public function testStartWorkerPostponedJob()
    {
        $job = $this->scheduler->addJob(SuccessJobMock::class, ['foo' => 'bar'], [
            Scheduler::OPTION_AT => time() + 1,
        ]);

        $this->worker->start();
        $job = $this->scheduler->getJob($job->getId());
        $this->assertSame(JobInterface::STATUS_POSTPONED, $job->getStatus());
    }

    public function testStartWorkerExecutePostponedJob()
    {
        $job = $this->scheduler->addJob(SuccessJobMock::class, ['foo' => 'bar'], [
            Scheduler::OPTION_AT => time() + 1,
        ]);

        $this->worker->start();
        $job = $this->scheduler->getJob($job->getId());
        $this->assertSame(JobInterface::STATUS_POSTPONED, $job->getStatus());
        sleep(1);
        $this->called = 0;
        $this->worker->start();
        $job = $this->scheduler->getJob($job->getId());
        $this->assertSame(JobInterface::STATUS_DONE, $job->getStatus());
    }

    public function testStartWorkerPostponedJobFromPast()
    {
        $job = $this->scheduler->addJob(SuccessJobMock::class, ['foo' => 'bar'], [
            Scheduler::OPTION_AT => time() - 1,
        ]);

        $this->worker->start();
        $job = $this->scheduler->getJob($job->getId());
        $this->assertSame(JobInterface::STATUS_DONE, $job->getStatus());
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
        $this->assertTrue($this->scheduler->getJob($new)->getOptions()['at'] > new UTCDateTime());
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

    public function testProcessErrorJobRetry()
    {
        $job = $this->scheduler->addJob(ErrorJobMock::class, ['foo' => 'bar'], [
            Scheduler::OPTION_RETRY => 1,
        ]);

        $this->assertSame(JobInterface::STATUS_WAITING, $job->getStatus());
        $this->worker->start();
        $job = $this->scheduler->getJob($job->getId());
        $this->assertSame(JobInterface::STATUS_FAILED, $job->getStatus());
        $retry_job = iterator_to_array($this->scheduler->getJobs())[0];
        $this->assertSame(JobInterface::STATUS_WAITING, $retry_job->getStatus());
        $this->assertSame(0, $retry_job->getOptions()['retry']);
    }

    public function testProcessJobInterval()
    {
        $job = $this->scheduler->addJob(SuccessJobMock::class, ['foo' => 'bar'], [
            Scheduler::OPTION_INTERVAL => 100,
        ]);

        $this->assertSame(JobInterface::STATUS_WAITING, $job->getStatus());
        $this->worker->start();
        $job = $this->scheduler->getJob($job->getId());
        $this->assertSame(JobInterface::STATUS_DONE, $job->getStatus());
        $interval_job = iterator_to_array($this->scheduler->getJobs())[0];
        $this->assertSame(JobInterface::STATUS_WAITING, $interval_job->getStatus());
        $this->assertSame(100, $interval_job->getOptions()['interval']);
        $this->assertTrue($interval_job->getOptions()['at'] > new UTCDateTime());
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
        $job = $this->scheduler->addJob(SuccessJobMock::class, ['foo' => 'bar']);
        $stub_container = $this->getMockBuilder(ContainerInterface::class)
            ->getMock();
        $stub_container->expects($this->once())->method('get')
            ->willReturn(new SuccessJobMock());

        $called = 0;
        $worker = $this->getMockBuilder(Worker::class)
            ->setConstructorArgs([new ObjectId(), $this->scheduler, $this->mongodb, $this->createMock(LoggerInterface::class), $stub_container])
            ->setMethods(['loop'])
            ->getMock();
        $worker->method('loop')
            ->will(
                $this->returnCallback(function () use (&$called) {
                    if (0 === $called) {
                        ++$called;

                        return true;
                    }

                    return false;
                })
        );

        $worker->start();
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
