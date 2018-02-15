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
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
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

    protected static function getMethod($name): ReflectionMethod
    {
        $class = new ReflectionClass(Async::class);
        $method = $class->getMethod($name);
        $method->setAccessible(true);

        return $method;
    }
}
