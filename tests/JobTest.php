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
use TaskScheduler\Testsuite\Mock\SuccessJobMock;

class JobTest extends TestCase
{
    protected $job;

    public function setUp(): void
    {
        $this->job = new SuccessJobMock();
    }

    public function testSetData()
    {
        $self = $this->job->setData(['foo' => 'bar']);
        $this->assertInstanceOf(SuccessJobMock::class, $self);
    }

    public function testGetData()
    {
        $self = $this->job->setData(['foo' => 'bar']);
        $data = $this->job->getData();
        $this->assertSame($data, ['foo' => 'bar']);
    }

    public function testSetId()
    {
        $id = new ObjectId();
        $self = $this->job->setId($id);
        $this->assertInstanceOf(SuccessJobMock::class, $self);
    }

    public function testGetId()
    {
        $id = new ObjectId();
        $self = $this->job->setId($id);
        $this->assertSame($self->getId(), $id);
    }

    public function testStart()
    {
        $result = $this->job->start();
        $this->assertSame($result, true);
    }
}
