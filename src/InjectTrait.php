<?php

declare(strict_types=1);

/**
 * TaskScheduler
 *
 * @author      Raffael Sahli <sahli@gyselroth.net>
 * @copyright   Copryright (c) 2017-2018 gyselroth GmbH (https://gyselroth.com)
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
