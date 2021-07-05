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

use Closure;
use League\Event\Emitter;

trait EventsTrait
{
    /**
     * Emitter.
     *
     * @var Emitter
     */
    protected $emitter;

    /**
     * Bind event listener.
     */
    public function on(string $event, Closure $handler)
    {
        if (!in_array($event, Scheduler::VALID_EVENTS, false) || '*' === $event) {
            $event = 'taskscheduler.on'.ucfirst($event);
        }

        $this->emitter->addListener($event, $handler);

        return $this;
    }

    /**
     * Emit process event.
     */
    protected function emit(Process $process): bool
    {
        $this->emitter->emit(Scheduler::VALID_EVENTS[$process->getStatus()], $process);

        return true;
    }
}
