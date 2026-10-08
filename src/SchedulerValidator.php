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
                    if (!is_int($value)) {
                        throw new InvalidArgumentException(
                            'option ' . $option . ' must be an integer'
                        );
                    }

                    if ($value < 0) {
                        throw new InvalidArgumentException(
                            'option ' . $option . ' must not be negative'
                        );
                    }

                    break;

                case Scheduler::OPTION_INTERVAL:
                    if (!is_int($value)) {
                        throw new InvalidArgumentException(
                            'option ' . $option . ' must be an integer'
                        );
                    }

                    /*
                     * 0  = no interval
                     * >0 = fixed interval
                     * -1 = endless interval
                     */
                    if ($value < -1) {
                        throw new InvalidArgumentException(
                            'option ' . $option . ' must be 0, -1 or a positive integer'
                        );
                    }

                    break;

                case Scheduler::OPTION_RETRY:
                    if (!is_int($value)) {
                        throw new InvalidArgumentException(
                            'option ' . $option . ' must be an integer'
                        );
                    }

                    if ($value < 0) {
                        throw new InvalidArgumentException(
                            'option ' . $option . ' must not be negative'
                        );
                    }

                    break;

                case Scheduler::OPTION_RETRY_INTERVAL:
                    if (!is_int($value)) {
                        throw new InvalidArgumentException(
                            'option ' . $option . ' must be an integer'
                        );
                    }

                    if ($value < 0) {
                        throw new InvalidArgumentException(
                            'option ' . $option . ' must not be negative'
                        );
                    }

                    break;

                case Scheduler::OPTION_TIMEOUT:
                    if (!is_int($value)) {
                        throw new InvalidArgumentException(
                            'option ' . $option . ' must be an integer'
                        );
                    }

                    if ($value < 0) {
                        throw new InvalidArgumentException(
                            'option ' . $option . ' must not be negative'
                        );
                    }

                    break;

                case Scheduler::OPTION_IGNORE_DATA:
                case Scheduler::OPTION_FORCE_SPAWN:
                    if (!is_bool($value)) {
                        throw new InvalidArgumentException(
                            'option ' . $option . ' must be a boolean'
                        );
                    }

                    break;

                case Scheduler::OPTION_ID:
                    if (!$value instanceof ObjectId) {
                        throw new InvalidArgumentException(
                            'option ' . $option .
                            ' must be an instance of ' .
                            ObjectId::class
                        );
                    }

                    break;

                case Scheduler::OPTION_INTERVAL_REFERENCE:
                    if (!in_array(
                        $value,
                        self::INTERVAL_REFERENCES,
                        true
                    )) {
                        throw new InvalidArgumentException(
                            'option ' . $option .
                            ' must be "start" or "end"'
                        );
                    }

                    break;

                default:
                    throw new InvalidArgumentException(
                        'invalid option ' . $option . ' given'
                    );
            }
        }

        /*
         * Retry without a retry interval is technically valid because
         * retry_interval may be 0. No additional constraint is necessary.
         */

        return $options;
    }
}
