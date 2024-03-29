<?php

declare(strict_types=1);

/**
 * TaskScheduler
 *
 * @author      gyselroth™  (http://www.gyselroth.com)
 * @copyright   Copryright (c) 2017-2022 gyselroth GmbH (https://gyselroth.com)
 * @license     MIT https://opensource.org/licenses/MIT
 */

namespace TaskScheduler\Testsuite\Mock;

use TaskScheduler\AbstractJob;

class SuccessJobMock extends AbstractJob
{
    public function start(): bool
    {
        return true;
    }
}
