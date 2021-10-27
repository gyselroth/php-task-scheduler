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
use Helmich\MongoMock\MockSession;
use http\Exception;
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

    public function setUp(): void
    {
        $this->mongodb = new MockDatabase();
        $this->scheduler = new Scheduler($this->mongodb, $this->createMock(LoggerInterface::class));
        $called = &$this->called;
        $this->worker = $this->getMockBuilder(Worker::class)
            ->setConstructorArgs([new ObjectId(), $this->scheduler, $this->mongodb, $this->createMock(LoggerInterface::class)])
            ->onlyMethods(['loop', 'exit'])
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
        $this->worker->method('exit')
            ->will(
                $this->returnCallback(function () {
                    return true;
                })
            );

        $sessionHandler = new MockSession();
        $reflection = new \ReflectionClass($this->worker);
        $reflection_property = $reflection->getProperty('sessionHandler');
        $reflection_property->setAccessible(true);

        $reflection_property->setValue($this->worker, $sessionHandler);
    }

    public function testStartWorkerNoJob()
    {
        $this->expectException(\Helmich\MongoMock\Exception::class);
        $this->worker->processAll();
    }

    public function testStartWorkerOneSuccessJob()
    {
        $start = new UTCDateTime();
        $job = $this->scheduler->addJob(SuccessJobMock::class, ['foo' => 'bar']);
        $this->assertSame(JobInterface::STATUS_WAITING, $job->getStatus());
        $this->worker->processOne($job->getId());
        $job = $this->scheduler->getJob($job->getId());
        $this->assertSame(JobInterface::STATUS_DONE, $job->getStatus());
        $this->assertTrue($job->toArray()['ended'] >= $start);
    }

    public function testStartWorkerOneErrorJob()
    {
        $start = new UTCDateTime();
        $job = $this->scheduler->addJob(ErrorJobMock::class, ['foo' => 'bar']);
        $this->assertSame(JobInterface::STATUS_WAITING, $job->getStatus());
        $this->worker->processOne($job->getId());
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

    public function testEventHandler()
    {
        $called1 = false;
        $this->scheduler->on('waiting', function ($e, $p) use (&$called1) {
            $this->assertSame(JobInterface::STATUS_WAITING, $p->getStatus());
            $called1 = true;
        });

        $called2 = false;
        $this->scheduler->on('processing', function ($e, $p) use (&$called2) {
            $this->assertSame(JobInterface::STATUS_PROCESSING, $p->getStatus());
            $called2 = true;
        });

        $called3 = false;
        $this->scheduler->on('done', function ($e, $p) use (&$called3) {
            $this->assertSame(JobInterface::STATUS_DONE, $p->getStatus());
            $called3 = true;
        });

        $job = $this->scheduler->addJob(SuccessJobMock::class, ['foo' => 'bar']);
        $this->worker->processOne($job->getId());
        $job->wait();
        $this->assertTrue($called1);
        $this->assertTrue($called2);
        $this->assertTrue($called3);
    }

    public function testEventHandlerPostponed()
    {
        $called1 = false;
        $this->scheduler->on('waiting', function ($e, $p) use (&$called1) {
            $this->assertSame(JobInterface::STATUS_WAITING, $p->getStatus());
            $called1 = true;
        });

        $called2 = false;
        $this->scheduler->on('postponed', function ($e, $p) use (&$called2) {
            $this->assertSame(JobInterface::STATUS_POSTPONED, $p->getStatus());
            $called2 = true;
        });

        $called3 = false;
        $this->scheduler->on('processing', function ($e, $p) use (&$called3) {
            $this->assertSame(JobInterface::STATUS_PROCESSING, $p->getStatus());
            $called3 = true;
        });

        $called4 = false;
        $this->scheduler->on('done', function ($e, $p) use (&$called4) {
            $this->assertSame(JobInterface::STATUS_DONE, $p->getStatus());
            $called4 = true;
        });

        $job = $this->scheduler->addJob(SuccessJobMock::class, ['foo' => 'bar'], [
            Scheduler::OPTION_AT => time() + 10,
        ]);

        $this->worker->processAll();
        $job = $this->scheduler->getJob($job->getId());
        $this->assertSame(JobInterface::STATUS_POSTPONED, $job->getStatus());

        //hook into the protected queue and reduce the time to wait
        $jobs_property = self::getProperty('queue');
        $jobs = $jobs_property->getValue($this->worker);
        $jobs[key($jobs)]['options']['at'] = time() - 15;
        $jobs_property->setValue($this->worker, $jobs);

        $this->called = 0;
        $this->worker->processAll();
        $job = $this->scheduler->getJob($job->getId());
        $this->assertSame(JobInterface::STATUS_DONE, $job->getStatus());

        $this->assertTrue($called1);
        $this->assertTrue($called2);
        $this->assertTrue($called3);
        $this->assertTrue($called4);
    }

    public function testExecuteSuccessfulJobWaitFor()
    {
        $job = $this->scheduler->addJob(SuccessJobMock::class, ['foo' => 'bar']);
        $this->assertSame(JobInterface::STATUS_WAITING, $job->getStatus());
        $this->worker->processOne($job->getId());
        $job->wait();
        $this->assertSame(JobInterface::STATUS_DONE, $job->getStatus());
    }

    public function testExecuteErrorJobWaitFor()
    {
        $job = $this->scheduler->addJob(ErrorJobMock::class, ['foo' => 'bar']);
        $this->assertSame(JobInterface::STATUS_WAITING, $job->getStatus());
        $this->worker->processOne($job->getId());
        $job->wait();
        $this->assertSame(JobInterface::STATUS_FAILED, $job->getStatus());
    }

    public function testStartWorkerPostponedJob()
    {
        $job = $this->scheduler->addJob(SuccessJobMock::class, ['foo' => 'bar'], [
            Scheduler::OPTION_AT => time() + 1,
        ]);

        $this->worker->processOne($job->getId());
        $job = $this->scheduler->getJob($job->getId());
        $this->assertSame(JobInterface::STATUS_POSTPONED, $job->getStatus());
    }

    public function testStartWorkerExecutePostponedJob()
    {
        $job = $this->scheduler->addJob(SuccessJobMock::class, ['foo' => 'bar'], [
            Scheduler::OPTION_AT => time() + 10,
        ]);

        $this->worker->processAll();
        $job = $this->scheduler->getJob($job->getId());
        $this->assertSame(JobInterface::STATUS_POSTPONED, $job->getStatus());

        //hook into the protected queue and reduce the time to wait
        $jobs_property = self::getProperty('queue');
        $jobs = $jobs_property->getValue($this->worker);
        $jobs[key($jobs)]['options']['at'] = time() - 15;
        $jobs_property->setValue($this->worker, $jobs);

        $this->called = 0;
        $this->worker->processAll();
        $job = $this->scheduler->getJob($job->getId());
        $this->assertSame(JobInterface::STATUS_DONE, $job->getStatus());
    }

    public function testStartWorkerExecutePostponedOverwrittenJob()
    {
        $job = $this->scheduler->addJob(SuccessJobMock::class, ['foo' => 'bar'], [
            Scheduler::OPTION_AT => time() + 1,
        ]);

        $this->worker->processAll();
        $job = $this->scheduler->getJob($job->getId());
        $this->assertSame(JobInterface::STATUS_POSTPONED, $job->getStatus());
        $this->mongodb->selectCollection('taskscheduler.jobs')->deleteMany([]);

        //hook into the protected queue and reduce the time to wait
        $jobs_property = self::getProperty('queue');
        $jobs = $jobs_property->getValue($this->worker);
        $jobs[key($jobs)]['options']['at'] = time() - 15;
        $jobs_property->setValue($this->worker, $jobs);

        $this->called = 0;
        $this->worker->processAll();
        $job = $this->scheduler->getJob($job->getId());
        $this->assertSame(JobInterface::STATUS_DONE, $job->getStatus());
    }

    public function testSetProgressTo100AfterFinish()
    {
        $job = $this->scheduler->addJob(SuccessJobMock::class, ['foo' => 'bar']);
        $job = (new SuccessJobMock())->setId($job->getId());

        $this->scheduler->updateJobProgress($job, 50.1);
        $this->worker->processOne($job->getId());
        $job = $this->scheduler->getJob($job->getId());
        $this->assertSame($job->getProgress(), 100.0);
    }

    public function testStartWorkerPostponedJobFromPast()
    {
        $job = $this->scheduler->addJob(SuccessJobMock::class, ['foo' => 'bar'], [
            Scheduler::OPTION_AT => time() - 1,
        ]);

        $this->worker->processOne($job->getId());
        $job = $this->scheduler->getJob($job->getId());
        $this->assertSame(JobInterface::STATUS_DONE, $job->getStatus());
    }

    public function testRescheduleLocalQueuedJobAfterTimeout()
    {
        $job = $this->scheduler->addJob(SuccessJobMock::class, ['foo' => 'bar'], [
            Scheduler::OPTION_AT => time() + 1,
        ]);

        $this->worker->processOne($job->getId());
        $job = $this->scheduler->getJob($job->getId());
        $this->worker->timeout();
        $job = $this->scheduler->getJob($job->getId());
        $this->assertSame(JobInterface::STATUS_POSTPONED, $job->getStatus());
    }

    public function testRescheduleLocalQueuedJobWithTheSameIdIfJobWasOverwrittenInQueue()
    {
        $job = $this->scheduler->addJob(SuccessJobMock::class, ['foo' => 'bar'], [
            Scheduler::OPTION_AT => time() + 1,
        ]);

        $this->worker->processAll();
        $this->worker->processAll();
        $this->mongodb->{'taskscheduler.jobs'}->deleteOne(['_id' => $job->getId()]);
        $this->worker->cleanup();
        $new = $this->scheduler->getJob($job->getId());
        $this->assertSame($new->getId(), $job->getId());
        $this->assertSame($new->toArray()['created'], $job->toArray()['created']);
        $this->assertSame(JobInterface::STATUS_WAITING, $job->getStatus());
        $this->assertSame($new->getOptions()['at'], $job->getOptions()['at']);
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
        $this->assertTrue($this->scheduler->getJob($new)->getOptions()['at'] > time());
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
        $this->worker->processAll();
        $job = $this->scheduler->getJob($job->getId());
        $this->assertSame(JobInterface::STATUS_FAILED, $job->getStatus());
        $retry_job = iterator_to_array($this->scheduler->getJobs())[0];
        $this->assertSame(JobInterface::STATUS_WAITING, $retry_job->getStatus());
        $this->assertSame(0, $retry_job->getOptions()['retry']);
        $this->assertEquals($retry_job->getOptions()['at'], time() + 300);
    }

    public function testProcessErrorJobRetryStopOnNull()
    {
        $job = $this->scheduler->addJob(ErrorJobMock::class, ['foo' => 'bar'], [
            Scheduler::OPTION_RETRY => 2,
            Scheduler::OPTION_RETRY_INTERVAL => 0,
        ]);

        $this->assertSame(JobInterface::STATUS_WAITING, $job->getStatus());
        $this->worker->processAll();
        $retry_job = iterator_to_array($this->scheduler->getJobs())[0];
        $this->assertSame(1, $retry_job->getOptions()['retry']);
        $this->called = 0;
        $this->worker->processAll();
        $retry_job = iterator_to_array($this->scheduler->getJobs())[0];
        $this->assertSame(0, $retry_job->getOptions()['retry']);
        $this->called = 0;
        $this->worker->processAll();
        $this->assertCount(0, iterator_to_array($this->scheduler->getJobs()));
    }

    public function testProcessEndlessRetry()
    {
        $job = $this->scheduler->addJob(ErrorJobMock::class, ['foo' => 'bar'], [
            Scheduler::OPTION_RETRY => -1,
            Scheduler::OPTION_RETRY_INTERVAL => 0,
        ]);

        $this->assertSame(JobInterface::STATUS_WAITING, $job->getStatus());
        $this->worker->processAll();
        $new_job = iterator_to_array($this->scheduler->getJobs())[0];
        $this->assertNotSame($job->getId(), $new_job->getId());
        $this->called = 0;
        $this->worker->processAll();
        $retry_job = iterator_to_array($this->scheduler->getJobs())[0];
        $this->assertNotSame($job->getId(), $new_job->getId());
        $this->called = 0;
        $this->worker->processAll();
        $retry_job = iterator_to_array($this->scheduler->getJobs())[0];
        $this->assertNotSame($job->getId(), $new_job->getId());
    }

    public function testProcessErrorJobRetryLowInterval()
    {
        $job = $this->scheduler->addJob(ErrorJobMock::class, ['foo' => 'bar'], [
            Scheduler::OPTION_RETRY => 1,
            Scheduler::OPTION_RETRY_INTERVAL => 10,
        ]);

        $this->assertSame(JobInterface::STATUS_WAITING, $job->getStatus());
        $this->worker->processAll();
        $job = $this->scheduler->getJob($job->getId());
        $this->assertSame(JobInterface::STATUS_FAILED, $job->getStatus());
        $retry_job = iterator_to_array($this->scheduler->getJobs())[0];
        $this->assertEquals($retry_job->getOptions()['at'], time() + 10);
    }

    public function testProcessJobInterval()
    {
        $job = $this->scheduler->addJob(SuccessJobMock::class, ['foo' => 'bar'], [
            Scheduler::OPTION_INTERVAL => 100,
        ]);

        $this->assertSame(JobInterface::STATUS_WAITING, $job->getStatus());
        $this->worker->processAll();
        $job = $this->scheduler->getJob($job->getId());
        $this->assertSame(JobInterface::STATUS_DONE, $job->getStatus());
        $interval_job = iterator_to_array($this->scheduler->getJobs())[0];
        $this->assertSame(JobInterface::STATUS_WAITING, $interval_job->getStatus());
        $this->assertSame(100, $interval_job->getOptions()['interval']);
        $this->assertTrue($interval_job->getOptions()['at'] > time());
    }

    public function testProcessJobIntervalReferenceStart()
    {
        $job = $this->scheduler->addJob(SuccessJobMock::class, ['foo' => 'bar'], [
            Scheduler::OPTION_INTERVAL => 100,
            Scheduler::OPTION_INTERVAL_REFERENCE => 'start',
        ]);

        $this->assertSame(JobInterface::STATUS_WAITING, $job->getStatus());
        $this->worker->processAll();
        $job = $this->scheduler->getJob($job->getId());
        $this->assertSame(JobInterface::STATUS_DONE, $job->getStatus());
        $interval_job = iterator_to_array($this->scheduler->getJobs())[0];
        $this->assertSame(JobInterface::STATUS_WAITING, $interval_job->getStatus());
        $this->assertSame('start', $interval_job->getOptions()['interval_reference']);
    }

    public function testProcessJobIntervalReferenceEnd()
    {
        $job = $this->scheduler->addJob(SuccessJobMock::class, ['foo' => 'bar'], [
            Scheduler::OPTION_INTERVAL => 100,
            Scheduler::OPTION_INTERVAL_REFERENCE => 'end',
        ]);

        $this->assertSame(JobInterface::STATUS_WAITING, $job->getStatus());
        $this->worker->processAll();
        $job = $this->scheduler->getJob($job->getId());
        $this->assertSame(JobInterface::STATUS_DONE, $job->getStatus());
        $interval_job = iterator_to_array($this->scheduler->getJobs())[0];
        $this->assertSame(JobInterface::STATUS_WAITING, $interval_job->getStatus());
        $this->assertSame('end', $interval_job->getOptions()['interval_reference']);
    }

    public function testProcessEndlessInterval()
    {
        $job = $this->scheduler->addJob(SuccessJobMock::class, ['foo' => 'bar'], [
            Scheduler::OPTION_INTERVAL => -1,
        ]);

        $this->assertSame(JobInterface::STATUS_WAITING, $job->getStatus());
        $this->worker->processAll();
        $new_job = iterator_to_array($this->scheduler->getJobs())[0];
        $this->assertNotSame($job->getId(), $new_job->getId());
        $this->called = 0;
        $this->worker->processAll();
        $retry_job = iterator_to_array($this->scheduler->getJobs())[0];
        $this->assertNotSame($job->getId(), $new_job->getId());
        $this->called = 0;
        $this->worker->processAll();
        $retry_job = iterator_to_array($this->scheduler->getJobs())[0];
        $this->assertNotSame($job->getId(), $new_job->getId());
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
        $worker = $this->getWorker();
        $worker->processAll();
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
        $result = $this->worker->cleanup();
        $this->assertNull($result);
    }

    public function testCleanupViaSigtermScheduleJob()
    {
        $job = $this->scheduler->addJob('test', ['foo' => 'bar']);
        $property = self::getProperty('current_job');
        $property->setValue($this->worker, $job->toArray());
        $new = $this->worker->cleanup();

        $this->assertInstanceOf(ObjectId::class, $new);
        $this->assertNotSame($job->getId(), $new);
    }

    protected function getWorker()
    {
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

        return $worker;
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
