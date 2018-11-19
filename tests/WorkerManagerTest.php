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

use MongoDB\BSON\ObjectId;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use TaskScheduler\Exception\InvalidArgumentException;
use TaskScheduler\Queue;
use TaskScheduler\WorkerFactoryInterface;
use TaskScheduler\WorkerManager;

class WorkerManagerTest extends TestCase
{
    protected $manager;
    protected $called = 1;

    public function setUp()
    {
        msg_remove_queue(msg_get_queue(ftok(__DIR__.'/../src/Queue.php', 't')));
        $called = &$this->called;
        $this->manager = $this->getMockBuilder(WorkerManager::class)
            ->setConstructorArgs([$this->createMock(WorkerFactoryInterface::class), $this->createMock(LoggerInterface::class)])
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

    /*public function testForceSpawnPostponedWorker()
    {
        $this->manager->setOptions([
            WorkerManager::OPTION_MIN_CHILDREN => 0,
            WorkerManager::OPTION_PM => WorkerManager::PM_STATIC,
        ]);

        $queue = msg_get_queue(ftok(dirname(__FILE__).'/../src/Queue.php', 't'));
        msg_send($queue, WorkerManager::TYPE_JOB, [
            '_id' => new ObjectId(),
            'options' => [
                'force_spawn' => true,
                'at' => time()+1,
            ]
        ]);

        $this->manager->process();
        $this->assertSame(0, $this->manager->count());
        $this->called = 8;
        sleep(1);
        $this->manager->process();
        $this->assertSame(1, $this->manager->count());
    }*/

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
