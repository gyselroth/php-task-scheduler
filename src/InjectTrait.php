<?php

declare(strict_types=1);

/**
 * TaskScheduler
 *
 * @author      gyselroth™  (http://www.gyselroth.com)
 * @copyright   Copryright (c) 2017-2021 gyselroth GmbH (https://gyselroth.com)
 * @license     MIT https://opensource.org/licenses/MIT
 */

namespace TaskScheduler;

trait InjectTrait
{
    /**
     * This method may seem useless but is actually very useful to mock the loop.
     */
    protected function loop(): bool
    {
        return true;
    }

    /**
     * This method may seem useless but is actually very useful to mock exits.
     */
    protected function exit(): bool
    {
        exit();
    }
}
