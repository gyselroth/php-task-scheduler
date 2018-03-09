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
use InvalidArgumentException;
use MongoDB\BSON\ObjectId;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TaskScheduler\Exception;
use TaskScheduler\Queue;
use TaskScheduler\Scheduler;

class SchedulerTest extends TestCase
{
    protected $scheduler;

    public function setUp()
    {
        $mongodb = new MockDatabase();
        $this->scheduler = new Scheduler($mongodb, $this->createMock(LoggerInterface::class));
    }

    public function testAddJob()
    {
        $id = $this->scheduler->addJob('test', ['foo' => 'bar']);
        $this->assertInstanceOf(ObjectId::class, $id);
    }

    public function testNewJobStatus()
    {
        $id = $this->scheduler->addJob('test', ['foo' => 'bar']);
        $job = $this->scheduler->getJob($id);
        $this->assertSame($job['status'], Queue::STATUS_WAITING);
    }

    public function testGetJob()
    {
        $id = $this->scheduler->addJob('test', ['foo' => 'bar']);
        $job = $this->scheduler->getJob($id);
        $this->assertSame($id, $job['_id']);
    }

    public function testGetJobs()
    {
        $id = $this->scheduler->addJob('test', ['foo' => 'bar']);
        $jobs = $this->scheduler->getJobs();
        $this->assertSame($jobs->toArray()[0]['_id'], $id);
        $this->assertTrue(is_array($jobs->toArray()[0]));
    }

    public function testGetClosedJobsWhenNoClosedJobsExist()
    {
        $id = $this->scheduler->addJob('test', ['foo' => 'bar']);
        $jobs = $this->scheduler->getJobs([Queue::STATUS_DONE]);
        $this->assertSame(0, count($jobs->toArray()));
    }

    public function testGetInexistingJob()
    {
        $this->expectException(Exception\JobNotFound::class);
        $job = $this->scheduler->getJob(new ObjectId());
    }

    public function testCancelJob()
    {
        $id = $this->scheduler->addJob('test', ['foo' => 'bar']);
        $this->scheduler->cancelJob($id);
        $job = $this->scheduler->getJob($id);
        $this->assertSame(Queue::STATUS_CANCELED, $job['status']);
    }

    public function testAddJobAdvanced()
    {
        $id = $this->scheduler->addJob('test', ['foo' => 'bar'], [
            Scheduler::OPTION_RETRY => 10,
            Scheduler::OPTION_INTERVAL => 60,
            Scheduler::OPTION_RETRY_INTERVAL => 10,
        ]);

        $job = $this->scheduler->getJob($id);
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
        $this->assertSame($first, $seccond);
        $jobs = $this->scheduler->getJobs();
        $this->assertSame(1, count($jobs->toArray()));
    }

    public function testInitDefaultOptions()
    {
        $mongodb = new MockDatabase();
        $this->scheduler = new Scheduler($mongodb, $this->createMock(LoggerInterface::class), [
            'collection_name' => 'jobs',
            'default_retry' => 1,
            'default_at' => 1000000,
            'default_retry_interval' => 1,
            'default_interval' => 300,
            'queue_size' => 10,
        ]);

        $id = $this->scheduler->addJob('test', ['foo' => 'bar']);
        $job = $this->scheduler->getJob($id);
        $this->assertSame(1, $job['retry']);
        $this->assertSame(1000000, (int) $job['at']->toDateTime()->format('U'));
        $this->assertSame(1, $job['retry_interval']);
        $this->assertSame(300, $job['interval']);
    }

    public function testCreateQueue()
    {
        $this->scheduler->createQueue();
    }
}
