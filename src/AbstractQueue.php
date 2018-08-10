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
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class AbstractQueue
{
    /**
     * Scheduler.
     *
     * @param Scheduler
     */
    protected $scheduler;

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
     * Collection name.
     *
     * @var string
     */
    protected $collection_name = 'queue';

    /**
     * Container.
     *
     * @var ContainerInterface
     */
    protected $container;

    /**
     * Process ID.
     *
     * @var string
     */
    protected $process;

    /**
     * Create queue and insert a dummy object to start cursor
     * Dummy object is required, otherwise we would get a dead cursor.
     *
     * @return Queue
     */
    protected function createQueue(): self
    {
        $this->logger->info('create new queue ['.$this->collection_name.']', [
            'category' => get_class($this),
            'pm' => $this->process,
        ]);

        try {
            $this->db->createCollection(
                $this->collection_name,
                [
                    'capped' => true,
                    'size' => $this->scheduler->getQueueSize(),
                ]
            );

            $this->db->{$this->collection_name}->insertOne(['class' => 'dummy']);
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
     * @return Queue
     */
    protected function convertQueue(): self
    {
        $this->logger->info('convert existing queue collection ['.$this->collection_name.'] into a capped collection', [
            'category' => get_class($this),
            'pm' => $this->process,
        ]);

        $this->db->command([
            'convertToCapped' => $this->collection_name,
            'size' => $this->scheduler->getQueueSize(),
        ]);

        $this->db->{$this->collection_name}->insertOne(['class' => 'dummy']);

        return $this;
    }

    /**
     * Retrieve next job.
     *
     * @param IteratorIterator $cursor
     */
    protected function retrieveNextJob(IteratorIterator $cursor)
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
    protected function getCursor(bool $tailable = true): IteratorIterator
    {
        $options = ['typeMap' => Scheduler::TYPE_MAP];

        if (true === $tailable) {
            $options['cursorType'] = Find::TAILABLE;
            $options['noCursorTimeout'] = true;
        }

        try {
            $cursor = $this->db->{$this->collection_name}->find([
                '$or' => [
                    ['status' => JobInterface::STATUS_WAITING],
                    ['status' => JobInterface::STATUS_POSTPONED],
                ],
            ], $options);
        } catch (ConnectionException | ServerException $e) {
            if (2 === $e->getCode()) {
                $this->convertQueue();

                return $this->getCursor($tailable);
            }

            throw $e;
        } catch (RuntimeException $e) {
            return $this->getCursor($tailable);
        }

        $iterator = new IteratorIterator($cursor);
        $iterator->rewind();

        return $iterator;
    }
}
