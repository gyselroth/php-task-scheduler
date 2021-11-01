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

use MongoDB\Database;
use Psr\Log\LoggerInterface;

class SessionHandler
{
    /**
     * Database.
     *
     * @var Database
     */
    protected $db;

    /**
     * LoggerInterface.
     *
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * Scheduler.
     *
     * @var array
     */
    public $transactionOptions;

    public function __construct(Database $db, LoggerInterface $logger)
    {
        $this->db = $db;
        $this->logger = $logger;
        $this->setOptions();
    }

    public function setOptions(): void
    {
        $this->transactionOptions = [
            'readConcern' => new \MongoDB\Driver\ReadConcern(\MongoDB\Driver\ReadConcern::LOCAL),
            'writeConcern' => new \MongoDB\Driver\WriteConcern(\MongoDB\Driver\WriteConcern::MAJORITY, 1000),
            'readPreference' => new \MongoDB\Driver\ReadPreference(\MongoDB\Driver\ReadPreference::RP_PRIMARY),
        ];
    }

    public function getOptions(): array
    {
        return $this->transactionOptions;
    }

    public function getSession(): \MongoDB\Driver\Session
    {
        return $this->db->getManager()->startSession();
    }
}
