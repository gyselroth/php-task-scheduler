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

use MongoDB\BSON\ObjectId;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use TaskScheduler\Exception\InvalidArgumentException;
use TaskScheduler\JobInterface;
use TaskScheduler\Queue;
use TaskScheduler\Worker;
use TaskScheduler\WorkerFactoryInterface;
use TaskScheduler\WorkerManager;

class WorkerManagerTest extends TestCase
{
    protected $manager;
    protected $called = 1;

    public function setUp(): void
    {
        $worker = $this->createMock(Worker::class);
        $factory = $this->createMock(WorkerFactoryInterface::class);

        //make sure we provide a worker process which is long enaugh alive to run tests
        $factory->method('buildWorker')->will($this->returnCallback(function () use ($worker) {
            sleep(2);

            return $worker;
        }));

        msg_remove_queue(msg_get_queue(ftok(__DIR__.'/../src/Queue.php', 't')));
        $called = &$this->called;
        $this->manager = $this->getMockBuilder(WorkerManager::class)
            ->setConstructorArgs([$factory, $this->createMock(LoggerInterface::class)])
            ->setMethods(['loop', 'exit'])
            ->getMock();

        $this->manager->method('loop')
            ->will(
                $this->returnCallback(function () use (&$called) {
                    if (0 === $called) {
                        return false;
                    }

                    --$called;

                    return true;
                })
            );
        $this->manager->method('exit')
            ->will(
                $this->returnCallback(function () {
                    return true;
                })
            );
    }

    public function testStartInitialDefaultWorkers()
    {
        $this->manager->process();
        $this->assertSame(1, $this->manager->count());
    }

    public function testStartTwoInitialDynamicWorkers()
    {
        $this->manager->setOptions([
            WorkerManager::OPTION_MIN_CHILDREN => 2,
        ]);

        $this->manager->process();
        $this->assertSame(2, $this->manager->count());
    }

    public function testTerminateStartedWorkers()
    {
        $this->manager->setOptions([
            WorkerManager::OPTION_MIN_CHILDREN => 2,
            WorkerManager::OPTION_MAX_CHILDREN => 4,
        ]);

        $this->manager->process();

        $method = self::getMethod('getForks');
        $forks = $method->invokeArgs($this->manager, []);
        $this->assertCount(2, $forks);

        foreach ($forks as $pid) {
            $this->assertNotSame(false, posix_getpgid($pid));
        }

        $this->manager->cleanup(SIGKILL);

        foreach ($forks as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertFalse(posix_getpgid($pid));
        }
    }

    public function testStartTwoStaticWorkers()
    {
        $this->manager->setOptions([
            WorkerManager::OPTION_MIN_CHILDREN => 2,
            WorkerManager::OPTION_PM => WorkerManager::PM_STATIC,
        ]);

        $this->manager->process();
        $this->assertSame(2, $this->manager->count());
    }

    public function testOndemandWorkersNoJobs()
    {
        $this->manager->setOptions([
            WorkerManager::OPTION_PM => WorkerManager::PM_ONDEMAND,
        ]);

        $this->manager->process();
        $this->assertSame(0, $this->manager->count());
    }

    public function testOndemandWorkersOneJob()
    {
        $this->manager->setOptions([
            WorkerManager::OPTION_PM => WorkerManager::PM_ONDEMAND,
            WorkerManager::OPTION_MIN_CHILDREN => 0,
        ]);

        $queue = msg_get_queue(ftok(__DIR__.'/../src/Queue.php', 't'));
        msg_send($queue, WorkerManager::TYPE_JOB, [
            '_id' => new ObjectId(),
            'options' => [
                'force_spawn' => false,
                'at' => 0,
            ],
        ]);

        $this->manager->process();
        $this->assertSame(1, $this->manager->count());
    }

    public function testDynamicWorkersUntilMax()
    {
        $this->manager->setOptions([
            WorkerManager::OPTION_MIN_CHILDREN => 0,
            WorkerManager::OPTION_MAX_CHILDREN => 2,
            WorkerManager::OPTION_PM => WorkerManager::PM_DYNAMIC,
        ]);

        //pcntl_signal(SIGTERM, function(){});
        //pcntl_signal(SIGCHLD, function (){});
        $queue = msg_get_queue(ftok(__DIR__.'/../src/Queue.php', 't'));

        for ($i = 0; $i <= 8; ++$i) {
            msg_send($queue, WorkerManager::TYPE_JOB, [
                '_id' => new ObjectId(),
                'options' => [
                    'force_spawn' => false,
                    'at' => 0,
                ],
            ]);
        }

        $this->called = 3;
        $this->assertSame(0, $this->manager->count());
        $this->manager->process();
        $this->assertSame(2, $this->manager->count());
    }

    public function testForceSpawnWorker()
    {
        $this->manager->setOptions([
            WorkerManager::OPTION_MIN_CHILDREN => 1,
            WorkerManager::OPTION_PM => WorkerManager::PM_STATIC,
        ]);

        $queue = msg_get_queue(ftok(__DIR__.'/../src/Queue.php', 't'));
        msg_send($queue, WorkerManager::TYPE_JOB, [
            '_id' => new ObjectId(),
            'options' => [
                'force_spawn' => true,
                'at' => 0,
            ],
        ]);

        $this->called = 1;
        $this->manager->process();
        $this->assertSame(2, $this->manager->count());
    }

    public function testCancelEvent()
    {
        /*$this->manager->setOptions([
            WorkerManager::OPTION_MIN_CHILDREN => 1,
            WorkerManager::OPTION_PM => WorkerManager::PM_DYNAMIC,
        ]);

        $job = new ObjectId();
        $queue = msg_get_queue(ftok(__DIR__.'/../src/Queue.php', 't'));

        $map = self::getProperty('job_map');
        $forks = self::getProperty('forks');

        $this->called = 1;
        $this->manager->process();

        msg_send($queue, WorkerManager::TYPE_EVENT, [
            'status' => JobInterface::STATUS_CANCELED,
            'job' => $job,
        ]);

        $forks_property = self::getProperty('forks');
        $forks = $forks_property->getValue($this->manager);
        $this->assertSame(1, count($forks));
        $key = key($forks);

        $map_property = self::getProperty('job_map');
        $map_property->setValue($this->manager, [
            (string) $key => $job,
        ]);
        $this->assertSame(1, count($map_property->getValue($this->manager)));

        $this->called = 1;
        $this->manager->process();

        $map = $map_property->getValue($this->manager);
        $this->assertSame(0, count($map));
        $this->assertSame(1, count($forks));*/
    }

    public function testForceSpawnPostponedWorker()
    {
        $this->manager->setOptions([
            WorkerManager::OPTION_MIN_CHILDREN => 0,
            WorkerManager::OPTION_PM => WorkerManager::PM_STATIC,
        ]);

        $queue = msg_get_queue(ftok(__DIR__.'/../src/Queue.php', 't'));
        msg_send($queue, WorkerManager::TYPE_JOB, [
            '_id' => new ObjectId(),
            'options' => [
                'force_spawn' => true,
                'at' => time() + 10,
            ],
        ]);

        $this->manager->process();
        $this->assertSame(0, $this->manager->count());
        $this->called = 3;

        //hook into the protected queue and reduce the time to wait
        $jobs_property = self::getProperty('onhold');
        $jobs = $jobs_property->getValue($this->manager);
        $jobs[key($jobs)]['options']['at'] = time() - 15;
        $jobs_property->setValue($this->manager, $jobs);

        $this->manager->process();
        $this->assertSame(1, $this->manager->count());
    }

    public function testMinChildrenGreaterThanMaxChildren()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->manager->setOptions([
            WorkerManager::OPTION_MIN_CHILDREN => 3,
            WorkerManager::OPTION_MAX_CHILDREN => 2,
        ]);
    }

    public function testNotExistingProcessHandlingOption()
    {
        $this->expectException(InvalidArgumentException::class);

        $this->manager->setOptions([
            WorkerManager::OPTION_PM => 'foo',
        ]);
    }

    public function testMinChildrenInteger()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->manager->setOptions([
            WorkerManager::OPTION_MIN_CHILDREN => 'foo',
        ]);
    }

    public function testMaxChildrenInteger()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->manager->setOptions([
            WorkerManager::OPTION_MAX_CHILDREN => 'foo',
        ]);
    }

    public function testNotExistingOption()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->manager->setOptions([
            'bar' => 'foo',
        ]);
    }

    protected static function getProperty($name): ReflectionProperty
    {
        $class = new ReflectionClass(WorkerManager::class);
        $property = $class->getProperty($name);
        $property->setAccessible(true);

        return $property;
    }

    protected static function getMethod($name): ReflectionMethod
    {
        $class = new ReflectionClass(WorkerManager::class);
        $method = $class->getMethod($name);
        $method->setAccessible(true);

        return $method;
    }
}
