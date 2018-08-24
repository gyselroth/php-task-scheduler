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

use InvalidArgumentException;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Database;
use MongoDB\UpdateResult;
use Psr\Log\LoggerInterface;
use Traversable;
use League\Event\Emitter;

class MessageQueue
{
    protected $db;
    protected $name;
    protected $size = 100000;

    /**
     * Message queue
     *
     * @var Database $db
     */
    public function __construct(Database $db, string $name, int $size)
    {
        $this->db = $db:;
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
            'pm' => $this->process,
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
     * Create queue and insert a dummy object to start cursor
     * Dummy object is required, otherwise we would get a dead cursor.
     *
     * @return AbstractQueue
     */
    protected function convert(): self
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

    /**
     * Retrieve next job.
     *
     * @param IteratorIterator $cursor
     */
    public function next(IteratorIterator $cursor)
    {
        try {
            $cursor->next();
        } catch (RuntimeException $e) {
            $this->logger->error('job queue cursor failed to retrieve next job, restart queue listener', [
                'category' => get_class($this),
                'pm' => $this->process,
                'exception' => $e,
            ]);

            $this->main();
        }
    }

    /**
     * Get cursor.
     *
     * @param bool $tailable
     *
     * @return IteratorIterator
     */
    public function getCursor(array $query = []): IteratorIterator
    {
        $options = [
            'typeMap' => Scheduler::TYPE_MAP,
            'cursorType'] => Find::TAILABLE,
            'noCursorTimeout'] = true,
        ];

        try {
            $cursor = $this->db->{$this->name}->find($query, $options);
        } catch (ConnectionException | ServerException $e) {
            if (2 === $e->getCode()) {
                $this->convert();

                return $this->getCursor($tailable);
            }

            throw $e;
        } catch (RuntimeException $e) {
            return $this->getCursor($query);
        }

        $iterator = new IteratorIterator($cursor);
        $iterator->rewind();

        return $iterator;
    }
}
