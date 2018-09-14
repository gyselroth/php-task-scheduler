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

use MongoDB\BSON\ObjectId;
use TaskScheduler\Exception\InvalidArgumentException;

class SchedulerValidator
{
    /**
     * Validate given job options.
     */
    public static function validateOptions(array $options): array
    {
        foreach ($options as $option => $value) {
            switch ($option) {
                case Scheduler::OPTION_AT:
                case Scheduler::OPTION_INTERVAL:
                case Scheduler::OPTION_RETRY:
                case Scheduler::OPTION_RETRY_INTERVAL:
                case Scheduler::OPTION_TIMEOUT:
                    if (!is_int($value)) {
                        throw new InvalidArgumentException('option '.$option.' must be an integer');
                    }

                break;
                case Scheduler::OPTION_IGNORE_DATA:
                case Scheduler::OPTION_IGNORE_MAX_CHILDREN:
                    if (!is_bool($value)) {
                        throw new InvalidArgumentException('option '.$option.' must be a boolean');
                    }

                break;
                case Scheduler::OPTION_ID:
                    if (!$value instanceof ObjectId) {
                        throw new InvalidArgumentException('option '.$option.' must be a an instance of '.ObjectId::class);
                    }

                break;
                default:
                    throw new InvalidArgumentException('invalid option '.$option.' given');
            }
        }

        return $options;
    }
}
