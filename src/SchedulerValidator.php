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

use MongoDB\BSON\ObjectId;
use TaskScheduler\Exception\InvalidArgumentException;

class SchedulerValidator
{
    /**
     * Interval references.
     */
    public const INTERVAL_REFERENCES = [
        'start',
        'end',
    ];

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
                case Scheduler::OPTION_FORCE_SPAWN:
                    if (!is_bool($value)) {
                        throw new InvalidArgumentException('option '.$option.' must be a boolean');
                    }

                break;
                case Scheduler::OPTION_ID:
                    if (!$value instanceof ObjectId) {
                        throw new InvalidArgumentException('option '.$option.' must be a an instance of '.ObjectId::class);
                    }

                break;
                case Scheduler::OPTION_INTERVAL_REFERENCE:
                    if (!in_array($value, self::INTERVAL_REFERENCES, true)) {
                        throw new InvalidArgumentException('option '.$option.' must be "start" or "end"');
                    }

                break;
                default:
                    throw new InvalidArgumentException('invalid option '.$option.' given');
            }
        }

        return $options;
    }
}
