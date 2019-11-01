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

trait EventsTrait
{
    protected function emit(Process $process): bool
    {
        $this->emitter->emit('taskscheduler.on', $process);

        switch ($process->getStatus()) {
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
