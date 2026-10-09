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

use MongoDB\Database;
use MongoDB\Driver\ReadConcern;
use MongoDB\Driver\ReadPreference;
use MongoDB\Driver\Session;
use MongoDB\Driver\WriteConcern;
use Psr\Log\LoggerInterface;

class SessionHandler
{
    /**
     * Transaction options.
     *
     * @var array
     */
    protected $transactionOptions = [];

    /**
     * Database.
     *
     * @var Database
     */
    protected $db;

    /**
     * Logger.
     *
     * @var LoggerInterface
     */
    protected $logger;

    public function __construct(
        Database $db,
        LoggerInterface $logger
    ) {
        $this->db = $db;
        $this->logger = $logger;

        $this->setOptions();
    }

    /**
     * Configure transaction options.
     */
    public function setOptions(): void
    {
        $this->transactionOptions = [
            /*
             * Transactions should use majority read concern.
             *
             * This gives the transaction a consistent view of data which
             * has been acknowledged by the replica set majority.
             */
            'readConcern' => new ReadConcern(
                ReadConcern::MAJORITY
            ),

            /*
             * A scheduler should not acknowledge a state transition if it
             * can immediately disappear after a primary failover.
             */
            'writeConcern' => new WriteConcern(
                WriteConcern::MAJORITY,
                1000
            ),

            // Worker/job state must always be read from the primary.
            'readPreference' => new ReadPreference(
                ReadPreference::PRIMARY
            ),
        ];
    }

    /**
     * Get transaction options.
     */
    public function getOptions(): array
    {
        return $this->transactionOptions;
    }

    /**
     * Start a new MongoDB session.
     */
    public function getSession(): Session
    {
        return $this->db
            ->getManager()
            ->startSession();
    }
}
