<?php

declare(strict_types=1);

/**
 * TaskScheduler
 *
 * @author      Raffael Sahli <sahli@gyselroth.net>
 * @copyright   Copryright (c) 2017-2019 gyselroth GmbH (https://gyselroth.com)
 * @license     MIT https://opensource.org/licenses/MIT
 */

namespace TaskScheduler;

use Closure;

trait EventsTrait
{
    /**
     * Valid events
     */
    public const VALID_EVENTS = [
        'taskscheduler.onStart',
        'taskscheduler.onDone',
        'taskscheduler.onPostponed',
        'taskscheduler.onFailed',
        'taskscheduler.onTimeout',
        'taskscheduler.onCancel',
        'taskscheduler.*',
    ];

    /**
     * Bind event listener
     */
    public function on(string $event, Closure $handler)
    {
        if(!in_array($event, self::VALID_EVENTS)) {
            $name = 'taskscheduler.on'.ucfirst($event);
        }

        $emitter->addListener($event, $handler);
        return $this;
    }

    /**
     * Emit process event
     */
    protected function emit(Process $process): bool
    {
        switch ($process->getStatus()) {
             case JobInterface::STATUS_WAITING:
                $this->emitter->emit('taskscheduler.onWaiting', $process);
                return true;
             case JobInterface::STATUS_PROCESSING:
                $this->emitter->emit('taskscheduler.onStart', $process);
                return true;
             case JobInterface::STATUS_DONE:
                $this->emitter->emit('taskscheduler.onDone', $process);
                return true;
             case JobInterface::STATUS_POSTPONED:
                $this->emitter->emit('taskscheduler.onPostponed', $process);
                return true;
             case JobInterfce::STATUS_FAILED:
                $this->emitter->emit('taskscheduler.onFailed', $process);
                return true;
             case JobInterface::STATUS_TIMEOUT:
                $this->emitter->emit('taskscheduler.onTimeout', $process);
                return true;
             case JobInterface::STATUS_CANCELED:
                $this->emitter->emit('taskscheduler.onCancel', $process);
                return true;
        }
    }
}
