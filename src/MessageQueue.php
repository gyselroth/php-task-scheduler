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

use IteratorIterator;
use MongoDB\Database;
use MongoDB\Driver\Exception\ConnectionException;
use MongoDB\Driver\Exception\RuntimeException;
use MongoDB\Driver\Exception\ServerException;
use MongoDB\Operation\Find;
use Psr\Log\LoggerInterface;

class MessageQueue
{
    protected $db;
    protected $name;
    protected $size = 100000;
    protected $logger;

    /**
     * Message queue.
     *
     * @var Database
     */
    public function __construct(Database $db, string $name, int $size, LoggerInterface $logger)
    {
        $this->db = $db;
        $this->name = $name;
        $this->size = $size;
        $this->logger = $logger;
    }

    /**
     * Create queue and insert a dummy object to start cursor
     * Dummy object is required, otherwise we would get a dead cursor.
     *
     * @return AbstractQueue
     */
    public function create(): self
    {
        $this->logger->info('create new queue ['.$this->name.']', [
            'category' => get_class($this),
        ]);

        try {
            $this->db->createCollection(
                $this->name,
                [
                    'capped' => true,
                    'size' => $this->size,
                ]
            );

            $this->db->{$this->name}->insertOne(['class' => 'dummy']);
        } catch (RuntimeException $e) {
            if (48 !== $e->getCode()) {
                throw $e;
            }
        }

        return $this;
    }

    /**
     * Retrieve next job.
     */
    public function next(IteratorIterator $cursor)
    {
        try {
            $cursor->next();
        } catch (RuntimeException $e) {
            $this->logger->error('job queue cursor failed to retrieve next job, restart queue listener', [
                'category' => get_class($this),
                'exception' => $e,
            ]);

            $this->main();
        }
    }

    /**
     * Get cursor.
     */
    public function getCursor(array $query = []): IteratorIterator
    {
        $options = [
            'typeMap' => Scheduler::TYPE_MAP,
            'cursorType' => Find::TAILABLE,
            'noCursorTimeout' => true,
        ];

        try {
            $cursor = $this->db->{$this->name}->find($query, $options);
        } catch (ConnectionException | ServerException $e) {
            if (2 === $e->getCode()) {
                $this->convert();

                return $this->getCursor($query);
            }

            throw $e;
        } catch (RuntimeException $e) {
            return $this->getCursor($query);
        }

        $iterator = new IteratorIterator($cursor);
        $iterator->rewind();

        return $iterator;
    }

    /**
     * Create queue and insert a dummy object to start cursor
     * Dummy object is required, otherwise we would get a dead cursor.
     *
     * @return AbstractQueue
     */
    public function convert(): self
    {
        $this->logger->info('convert existing queue collection ['.$this->name.'] into a capped collection', [
            'category' => get_class($this),
        ]);

        $this->db->command([
            'convertToCapped' => $this->name,
            'size' => $this->size,
        ]);

        $this->db->{$this->name}->insertOne(['class' => 'dummy']);

        return $this;
    }
}
