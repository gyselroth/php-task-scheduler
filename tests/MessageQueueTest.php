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

use Helmich\MongoMock\MockCollection;
use Helmich\MongoMock\MockCursor;
use Helmich\MongoMock\MockDatabase;
use IteratorIterator;
use MongoDB\Driver\Exception\ConnectionException;
use MongoDB\Driver\Exception\RuntimeException;
use MongoDB\Driver\Exception\ServerException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TaskScheduler\MessageQueue;

class MessageQueueTest extends TestCase
{
    protected $mongodb;
    protected $queue;

    public function setUp(): void
    {
        $this->mongodb = new MockDatabase();
        $this->queue = new MessageQueue($this->mongodb, 'taskscheduler.queue', 100, $this->createMock(LoggerInterface::class));
    }

    public function testCursor()
    {
        $cursor = $this->queue->getCursor();
        $this->assertSame(0, count(iterator_to_array($cursor)));
        $this->mongodb->{'taskscheduler.queue'}->insertOne(['foo' => 'bar']);
        $cursor = $this->queue->getCursor();
        $this->assertSame(1, count(iterator_to_array($cursor)));
    }

    public function testCursorRetrieveNext()
    {
        $this->mongodb->{'taskscheduler.queue'}->insertOne(['foo' => 'bar']);
        $this->mongodb->{'taskscheduler.queue'}->insertOne(['foo' => 'foo']);
        $cursor = $this->queue->getCursor();
        $this->assertSame('bar', $cursor->current()['foo']);
        $this->queue->next($cursor, function () {});
        $this->assertSame('foo', $cursor->current()['foo']);
    }

    public function testCursorRetrieveNextExceptionCallCallback()
    {
        $cursor = $this->createMock(IteratorIterator::class);
        $cursor->expects($this->once())->method('next')->will($this->throwException(new RuntimeException('cursor failure')));
        $called = false;
        $this->queue->next($cursor, function () use (&$called) {
            $called = true;
        });
        $this->assertTrue($called);
    }

    public function testCreate()
    {
        $this->queue->create();
        $queue = iterator_to_array($this->mongodb->listCollections())[0];
        $this->assertTrue($queue->isCapped());
        $this->assertSame(100, $queue->getCappedSize());
    }

    public function testCreateQueueAlreadyExistsNoException()
    {
        $this->queue->create();
        $this->queue->create();
        $this->assertSame(1, count(iterator_to_array($this->mongodb->listCollections())));
    }

    public function testCreateQueueExceptionIfNotCode48()
    {
        $this->expectException(RuntimeException::class);
        $mongodb = $this->createMock(MockDatabase::class);
        $mongodb->expects($this->once())->method('createCollection')->will($this->throwException(new RuntimeException('error')));
        $queue = new MessageQueue($mongodb, 'taskscheduler.queue', 100, $this->createMock(LoggerInterface::class));
        $queue->create();
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

    public function testConvertQueue()
    {
        $this->assertSame($this->queue, $this->queue->convert());
    }
}
