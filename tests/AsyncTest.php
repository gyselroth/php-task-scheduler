<?php

declare(strict_types=1);

/**
 * TaskScheduler
 *
 * @author      Raffael Sahli <sahli@gyselroth.net>
 * @copyright   Copryright (c) 2017 gyselroth GmbH (https://gyselroth.com)
 * @license     MIT https://opensource.org/licenses/MIT
 */

namespace TaskScheduler\Testsuite;

use Helmich\MongoMock\MockDatabase;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TaskScheduler\Async;

/**
 * @coversNothing
 */
class TaskScheduler extends TestCase
{
    protected $async;

    public function setUp()
    {
        $mongodb = new MockDatabase();
        $this->async = new Async($mongodb, $this->createMock(LoggerInterface::class));
    }

    public function testAddJob()
    {
        $result = $this->async->addJob('test', ['foo' => 'bar']);
        $this->assertTrue($result);
    }
}
