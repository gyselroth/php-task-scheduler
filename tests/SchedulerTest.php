<?php

declare(strict_types=1);

/**
 * TaskScheduler
 *
 * @author      Raffael Sahli <sahli@gyselroth.net>
 * @copyright   Copryright (c) 2017-2019 gyselroth GmbH (https://gyselroth.com)
 * @license     MIT https://opensource.org/licenses/MIT
 */

namespace TaskScheduler\Testsuite;

use Helmich\MongoMock\MockDatabase;
use MongoDB\BSON\ObjectId;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TaskScheduler\Exception\InvalidArgumentException;
use TaskScheduler\Exception\LogicException;
use TaskScheduler\Exception\JobNotFoundException;
use TaskScheduler\JobInterface;
use TaskScheduler\Process;
use TaskScheduler\Scheduler;
use MongoDB\BSON\UTCDateTime;

class SchedulerTest extends TestCase
{
    protected $scheduler;

    public function setUp(): void
    {
        $mongodb = new MockDatabase();
        $this->scheduler = new Scheduler($mongodb, $this->createMock(LoggerInterface::class));
    }

    public function testAddJob()
    {
        $job = $this->scheduler->addJob('test', ['foo' => 'bar']);
        $this->assertInstanceOf(Process::class, $job);
    }

    public function testNewJob()
    {
        $job = $this->scheduler->addJob('test', ['foo' => 'bar']);
        $this->assertSame($job->getStatus(), JobInterface::STATUS_WAITING);
        $this->assertSame($job->getClass(), 'test');
        $this->assertSame(['foo' => 'bar'], $job->getData());
        $this->assertInstanceOf(ObjectId::class, $job->getId());
        $this->assertInstanceOf(ObjectId::class, $job->getWorker());
    }

    public function testAddJobWithCustomIdInvalidValue()
    {
        $this->expectException(InvalidArgumentException::class);
        $job = $this->scheduler->addJob('test', ['foo' => 'bar'], [
            Scheduler::OPTION_ID => 'foobar',
        ]);
    }

    public function testAddJobWithCustomId()
    {
        $id = new ObjectId();
        $job = $this->scheduler->addJob('test', ['foo' => 'bar'], [
            Scheduler::OPTION_ID => $id,
        ]);

        $this->assertSame($id, $job->getId());
    }

    public function testAddJobWithCustomIdAlreadyExists()
    {
        $this->expectException(\Exception::class);
        $id = new ObjectId();
        $job = $this->scheduler->addJob('test', ['foo' => 'bar'], [
            Scheduler::OPTION_ID => $id,
        ]);

        $job = $this->scheduler->addJob('test', ['foo' => 'bar'], [
            Scheduler::OPTION_ID => $id,
        ]);
    }

    public function testFlush()
    {
        $this->expectException(JobNotFoundException::class);
        $job = $this->scheduler->addJob('test', ['foo' => 'bar']);
        $this->scheduler->flush();
        $this->scheduler->getJob($job->getId());
    }

    public function testNewJobTimestamps()
    {
        $ts = new UTCDateTime();
        $job = $this->scheduler->addJob('test', ['foo' => 'bar'])->toArray();
        $this->assertTrue($job['created'] >= $ts);
        $this->assertTrue($job['started'] >= $ts);
        $this->assertTrue($job['ended'] >= $ts);
    }

    public function testGetJob()
    {
        $job_add = $this->scheduler->addJob('test', ['foo' => 'bar']);
        $job_get = $this->scheduler->getJob($job_add->getId());
        $this->assertInstanceOf(Process::class, $job_get);
        $this->assertSame($job_add->getId(), $job_get->getId());
    }

    public function testGetJobs()
    {
        $job = $this->scheduler->addJob('test', ['foo' => 'bar']);
        $jobs = $this->scheduler->getJobs();
        $jobs = iterator_to_array($jobs);
        $this->assertSame($jobs[0]->getId(), $job->getId());
        $this->assertInstanceOf(Process::class, $jobs[0]);
    }

    public function testGetClosedJobsWhenNoClosedJobsExist()
    {
        $id = $this->scheduler->addJob('test', ['foo' => 'bar']);
        $jobs = $this->scheduler->getJobs(['status' => JobInterface::STATUS_DONE]);
        $this->assertSame(0, count(iterator_to_array($jobs)));
    }

    public function testGetInexistingJob()
    {
        $this->expectException(JobNotFoundException::class);
        $job = $this->scheduler->getJob(new ObjectId());
    }

    public function testCancelJob()
    {
        $job = $this->scheduler->addJob('test', ['foo' => 'bar']);
        $this->scheduler->cancelJob($job->getId());
        $job = $this->scheduler->getJob($job->getId());
        $this->assertSame(JobInterface::STATUS_CANCELED, $job->getStatus());
    }

    public function testCancelJobNotFound()
    {
        $this->expectException(JobNotFoundException::class);
        $this->scheduler->cancelJob(new ObjectId());
    }

    public function testCancelCanceledJob()
    {
        $job = $this->scheduler->addJob('test', ['foo' => 'bar']);
        $this->scheduler->cancelJob($job->getId());
        $this->scheduler->cancelJob($job->getId());
        $job = $this->scheduler->getJob($job->getId());
        $this->assertSame(JobInterface::STATUS_CANCELED, $job->getStatus());
    }

    public function testAddJobAdvanced()
    {
        $job = $this->scheduler->addJob('test', ['foo' => 'bar'], [
            Scheduler::OPTION_RETRY => 10,
            Scheduler::OPTION_INTERVAL => 60,
            Scheduler::OPTION_RETRY_INTERVAL => 10,
        ])->getOptions();

        $this->assertSame(10, $job[Scheduler::OPTION_RETRY]);
        $this->assertSame(60, $job[Scheduler::OPTION_INTERVAL]);
        $this->assertSame(10, $job[Scheduler::OPTION_RETRY_INTERVAL]);
    }

    public function testAddJobAdvancedInvalidValue()
    {
        $this->expectException(InvalidArgumentException::class);
        $id = $this->scheduler->addJob('test', ['foo' => 'bar'], [
            Scheduler::OPTION_RETRY => '10',
            Scheduler::OPTION_INTERVAL => 60,
            Scheduler::OPTION_RETRY_INTERVAL => 10,
        ]);
    }

    public function testAddJobAdvancedInvalidOption()
    {
        $this->expectException(InvalidArgumentException::class);
        $id = $this->scheduler->addJob('test', ['foo' => 'bar'], [
            'foobar' => 1,
        ]);
    }

    public function testAddJobOnlyOnce()
    {
        $data = uniqid();
        $first = $this->scheduler->addJobOnce('test', $data);
        $seccond = $this->scheduler->addJobOnce('test', $data);
        $this->assertEquals($first->getId(), $seccond->getId());
        $this->assertSame(JobInterface::STATUS_WAITING, $this->scheduler->getJob($seccond->getId())->getStatus());
        $jobs = $this->scheduler->getJobs();
        $this->assertSame(1, count(iterator_to_array($jobs)));
    }

    public function testAddJobOnlyOnceRescheduleIfOptionsChange()
    {
        $data = uniqid();
        $first = $this->scheduler->addJobOnce('test', $data, [
            Scheduler::OPTION_INTERVAL => 1,
        ]);

        $seccond = $this->scheduler->addJobOnce('test', $data, [
            Scheduler::OPTION_INTERVAL => 2,
        ]);

        $this->assertNotEquals($first->getId(), $seccond->getId());

        $this->assertSame(JobInterface::STATUS_CANCELED, $this->scheduler->getJob($first->getId())->getStatus());
        $jobs = $this->scheduler->getJobs();
        $this->assertSame(1, count(iterator_to_array($jobs)));
    }

    public function testAddJobOnlyOnceNotRescheduleIfOptionsChangeOnlyCompareSubmited()
    {
        $data = uniqid();
        $first = $this->scheduler->addJobOnce('test', $data, [
            Scheduler::OPTION_INTERVAL => 1,
            Scheduler::OPTION_AT => time() + 3600,
        ]);

        $seccond = $this->scheduler->addJobOnce('test', $data, [
            Scheduler::OPTION_INTERVAL => 1,
        ]);

        $this->assertEquals($first->getId(), $seccond->getId());
    }

    public function testUpdateJobProgressTooLow()
    {
        $this->expectException(LogicException::class);
        $job = $this->createMock(JobInterface::class);
        $this->scheduler->updateJobProgress($job, -0.1);
    }

    public function testUpdateJobProgressTooHigh()
    {
        $this->expectException(LogicException::class);
        $job = $this->createMock(JobInterface::class);
        $this->scheduler->updateJobProgress($job, 101);
    }

    public function testUpdateJobProgress()
    {
        $process = $this->scheduler->addJob('test', ['foo' => 'bar']);
        $job = $this->createMock(JobInterface::class);
        $job->method('getId')->willReturn($process->getId());

        $this->scheduler->updateJobProgress($job, 50.5);
        $process = $this->scheduler->getJob($process->getId());
        $this->assertSame(50.5, $process->getProgress());
    }

    public function testUpdateJobProgressRateLimit()
    {
        $process = $this->scheduler->addJob('test', ['foo' => 'bar']);
        $job = $this->createMock(JobInterface::class);
        $job->method('getId')->willReturn($process->getId());

        $this->scheduler->updateJobProgress($job, 50.5);
        $this->scheduler->updateJobProgress($job, 50.6);
        $process = $this->scheduler->getJob($process->getId());
        $this->assertSame(50.5, $process->getProgress());
    }

    public function testUpdateJobProgressNoRateLimit()
    {
        $this->scheduler->setOptions([
            Scheduler::OPTION_PROGRESS_RATE_LIMIT => 0,
        ]);

        $process = $this->scheduler->addJob('test', ['foo' => 'bar']);
        $job = $this->createMock(JobInterface::class);
        $job->method('getId')->willReturn($process->getId());

        $this->scheduler->updateJobProgress($job, 50.5);
        $this->scheduler->updateJobProgress($job, 50.6);
        $process = $this->scheduler->getJob($process->getId());
        $this->assertSame(50.6, $process->getProgress());
    }

    public function testAddOnceDifferentData()
    {
        $first = $this->scheduler->addJobOnce('test', 'foo');
        $seccond = $this->scheduler->addJobOnce('test', 'bar');
        $this->assertNotEquals($first->getId(), $seccond->getId());
        $this->assertSame(JobInterface::STATUS_WAITING, $this->scheduler->getJob($first->getId())->getStatus());
        $this->assertSame(JobInterface::STATUS_WAITING, $this->scheduler->getJob($seccond->getId())->getStatus());
        $jobs = $this->scheduler->getJobs();
        $this->assertSame(2, count(iterator_to_array($jobs)));
    }

    public function testAddOnceIgnoreData()
    {
        $first = $this->scheduler->addJobOnce('test', 'foo', [
            Scheduler::OPTION_IGNORE_DATA => true,
        ]);

        $seccond = $this->scheduler->addJobOnce('test', 'bar', [
            Scheduler::OPTION_IGNORE_DATA => true,
        ]);

        $this->assertNotEquals($first->getId(), $seccond->getId());

        $this->assertSame(JobInterface::STATUS_CANCELED, $this->scheduler->getJob($first->getId())->getStatus());
        $this->assertSame(JobInterface::STATUS_WAITING, $this->scheduler->getJob($seccond->getId())->getStatus());
        $jobs = $this->scheduler->getJobs();
        $this->assertSame(1, count(iterator_to_array($jobs)));
    }

    public function testAddJobWithIgnoreDataInvalidValue()
    {
        $this->expectException(InvalidArgumentException::class);
        $job = $this->scheduler->addJob('test', ['foo' => 'bar'], [
            Scheduler::OPTION_IGNORE_DATA => 'foobar',
        ]);
    }

    public function testInitChangeDefaultJobOptions()
    {
        $mongodb = new MockDatabase();
        $scheduler = new Scheduler($mongodb, $this->createMock(LoggerInterface::class), [
            'default_retry' => 1,
            'default_at' => 1000000,
            'default_retry_interval' => 1,
            'default_interval' => 300,
            'job_queue' => 'foo',
            'job_queue_size' => 10,
            'event_queue' => 'bar',
            'event_queue_size' => 50,
        ]);

        $job = $scheduler->addJob('test', ['foo' => 'bar'])->getOptions();
        $this->assertSame(1, $job['retry']);
        $this->assertSame(1000000, $job['at']);
        $this->assertSame(1, $job['retry_interval']);
        $this->assertSame(300, $job['interval']);
    }

    public function testChangeDefaultJobOptions()
    {
        $this->scheduler->setOptions([
            'default_retry' => 1,
            'default_at' => 1000000,
            'default_retry_interval' => 1,
            'default_interval' => 300,
        ]);

        $job = $this->scheduler->addJob('test', ['foo' => 'bar'])->getOptions();
        $this->assertSame(1, $job['retry']);
        $this->assertSame(1000000, $job['at']);
        $this->assertSame(1, $job['retry_interval']);
        $this->assertSame(300, $job['interval']);
    }

    public function testChangeQueueOptions()
    {
        $this->scheduler->setOptions([
            'job_queue' => 'foo',
            'job_queue_size' => 10,
            'event_queue' => 'bar',
            'event_queue_size' => 50,
        ]);

        $this->assertSame('foo', $this->scheduler->getJobQueue());
        $this->assertSame('bar', $this->scheduler->getEventQueue());
        $this->assertSame(10, $this->scheduler->getJobQueueSize());
        $this->assertSame(50, $this->scheduler->getEventQueueSize());
    }

    public function testChangeInvalidOption()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->scheduler->setOptions([
            'foo' => 'bar',
        ]);
    }

    public function testListen()
    {
        $called = false;
        $job = $this->scheduler->addJob('test', 'foobar');
        $this->scheduler->listen(function (Process $process) use(&$called) {
            $called = true;
            return true;
        }, ['job' => $job->getId()]);

        $this->assertTrue($called);
    }
}
