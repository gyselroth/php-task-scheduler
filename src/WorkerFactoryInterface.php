<?php

declare(strict_types=1);

/**
 * TaskScheduler
 *
 * @author      gyselroth™  (http://www.gyselroth.com)
 * @copyright   Copryright (c) 2017-2022 gyselroth GmbH (https://gyselroth.com)
 * @license     MIT https://opensource.org/licenses/MIT
 */

namespace TaskScheduler;

use MongoDB\BSON\ObjectId;

interface WorkerFactoryInterface
{
    /**
     * Build worker.
     */
    public function buildWorker(ObjectId $id): Worker;

    /**
     * Build manager.
     */
    public function buildManager(): WorkerManager;
}
