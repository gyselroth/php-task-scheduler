<?php

declare(strict_types=1);

/**
 * Balloon
 *
 * @author      Raffael Sahli <sahli@gyselroth.net>
 * @copyright   Copryright (c) 2012-2017 gyselroth GmbH (https://gyselroth.com)
 * @license     GPL-3.0 https://opensource.org/licenses/GPL-3.0
 */

namespace TaskScheduler\Testsuite;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Helmich\MongoMock\MockDatabase;
use TaskScheduler\Async;
use TaskScheduler\JobInterface;

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
