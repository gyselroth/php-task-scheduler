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

use Helmich\MongoMock\MockCollection;
use Helmich\MongoMock\MockCursor;
use Helmich\MongoMock\MockDatabase;
use MongoDB\Driver\Exception\ConnectionException;
use MongoDB\Driver\Exception\RuntimeException;
use MongoDB\Driver\Exception\ServerException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TaskScheduler\MessageQueue;

class MessageQueueTest extends TestCase
{
    protected $queue;

    public function setUp()
    {
        $mongodb = new MockDatabase();
        $this->queue = new MessageQueue($mongodb, 'taskscheduler.queue', 100, $this->createMock(LoggerInterface::class));
    }

    public function testCursor()
    {
        $cursor = $this->queue->getCursor();
        /*$id = $this->scheduler->addJob('test', ['foo' => 'bar']);

        $job = $this->scheduler->getJob($id);
        $method = self::getMethod('getCursor');
        $cursor = $method->invokeArgs($this->queue, []);
        $this->assertSame(1, count($cursor->toArray()));*/
    }

    public function testCursorRetrieveNext()
    {
        $cursor = $this->queue->getCursor();
        $this->queue->next($cursor);
    }

    public function testCreate()
    {
        $this->queue->create();
        //$this->assertSame('dummy', $mongodb->{$scheduler->getCollection()}->findOne([])['class']);
    }

    public function testCreateQueueAlreadyExistsNoException()
    {
        $this->queue->create();
        $this->queue->create();
    }

    public function testCreateQueueRuntimeException()
    {
        $this->expectException(RuntimeException::class);
        $mongodb = $this->createMock(MockDatabase::class);
        $mongodb->expects($this->once())->method('createCollection')->will($this->throwException(new RuntimeException('error')));
        $this->expectException(RuntimeException::class);

        $queue = new MessageQueue($mongodb, 'taskscheduler.queue', 100, $this->createMock(LoggerInterface::class));
        $queue->create();
    }

    public function testCursorConnectionExceptionNotTailable()
    {
        $collection = $this->createMock(MockCollection::class);
        $exception = true;
        $collection->expects($this->once())->method('find')->will($this->returnCallback(function () use ($exception) {
            if (true === $exception) {
                $exception = false;

                if (class_exists(ServerException::class)) {
                    $this->throwException(new ServerException('not tailable', 2));
                } else {
                    $this->throwException(new ConnectionException('not tailable', 2));
                }
            }

            return new MockCursor();
        }));

        $mongodb = $this->createMock(MockDatabase::class);
        $mongodb->method('__get')->willReturn($collection);
        $queue = new MessageQueue($mongodb, 'taskscheduler.queue', 100, $this->createMock(LoggerInterface::class));
        $cursor = $queue->getCursor();
    }

    /*public function testCursorConnectionException()
    {
        $this->expectException(ConnectionException::class);
        $collection = $this->createMock(MockCollection::class);
        $collection->expects($this->once())->method('find')->will($this->throwException(new ConnectionException('error')));
        $mongodb = $this->createMock(MockDatabase::class);
        $mongodb->method('__get')->willReturn($collection);
        $queue = new Queue($this->createMock(Scheduler::class), $mongodb, $this->createMock(WorkerFactoryInterface::class), $this->createMock(LoggerInterface::class));
        $queue->processOnce();
    }*/

    public function testConvertQueue()
    {
        $this->queue->convert();
    }
}
