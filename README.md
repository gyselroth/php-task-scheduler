# Clustered process management for PHP

[![Build Status](https://travis-ci.com/gyselroth/php-task-scheduler.svg?branch=master)](https://travis-ci.com/gyselroth/php-task-scheduler)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/gyselroth/mongodb-php-task-scheduler/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/gyselroth/mongodb-php-task-scheduler/?branch=master)
[![Code Coverage](https://scrutinizer-ci.com/g/gyselroth/mongodb-php-task-scheduler/badges/coverage.png?b=master)](https://scrutinizer-ci.com/g/gyselroth/mongodb-php-task-scheduler/?branch=master)
[![Latest Stable Version](https://img.shields.io/packagist/v/gyselroth/mongodb-php-task-scheduler.svg)](https://packagist.org/packages/gyselroth/mongodb-php-task-scheduler)
[![GitHub release](https://img.shields.io/github/release/gyselroth/php-task-scheduler.svg)](https://github.com/gyselroth/php-task-scheduler/releases)
[![GitHub license](https://img.shields.io/badge/license-MIT-blue.svg)](https://raw.githubusercontent.com/gyselroth/php-task-scheduler/master/LICENSE)

Parallel task scheduler for PHP using MongoDB as distribution queue. Execute parallel tasks the easy way.
This library has built-in support for clustered systems and multi core cpu. You can start up multiple worker nodes and they will load balance the available jobs with the principal first comes first serves. Each node will also spawn a (dynamically) configurable number of child processes to use all available resources. Moreover it is possible to schedule jobs at certain times, endless intervals as well as rescheduling if jobs fail.
This brings a real world implementation for parallel process management to PHP. You are also able to sync child tasks and much more nice stuff.

## Features

* Parallel tasks
* Cluster support
* Multi core support
* Load balancing
* Failover
* Scalable
* Sync tasks between each other
* Abort running tasks
* Timeout jobs
* Retry and intervals
* Schedule tasks at specific times
* Signal management
* Intercept events
* Progress support
* Auto detection of orphaned jobs

## v4

This is the documentation for the current major version v4. You may check the [upgrade guide](https://github.com/gyselroth/php-task-scheduler/blob/master/UPGRADE.md) if you want to upgrade from v3 or even an older version.
The documentation for v3 is available [here](https://github.com/gyselroth/php-task-scheduler/blob/3.x/README.md).

# Table of Contents
* [Features](#features)
* [Why?](#why)
* [How does it work (The short way please)?](#how-does-it-work-the-short-way-please)
* [Requirements](#requirements)
* [Download](#download)
* [Changelog](#changelog)
* [Contribute](#contribute)
* [Terms](#terms)
* [Documentation](#documentation)
    * [Create job](#create-job)
    * [Initialize scheduler](#initialize-scheduler)
    * [Spool job](#spool-job)
    * [Execute jobs](#execute-jobs)
        * [Create worker factory](#create-worker-factory)
        * [Create queue node](#create-queue-node)
    * [Manage jobs](#manage-jobs)
        * [Get jobs](#get-jobs)
        * [Cancel job](#cancel-job)
        * [Modify jobs](#modify-jobs)
    * [Handling of failed jobs](#handling-of-failed-jobs)
    * [Job progess](#job-progress)
    * [Asynchronous programming](#asynchronous-programming)
    * [Listen for Events](#listen-for-events)
        * [Bind events](#bind-events)
    * [Advanced job options](#advanced-job-options)
    * [Add job if not exists](#add-job-if-not-exists)
    * [Advanced worker manager options](#advanced-worker-manager-options)
    * [Advanced queue node options](#advanced-queue-node-options)
    * [Using a PSR-11 DIC](#using-a-psr-11-dic)
    * [Data Persistency](#data-persistency)
    * [Signal handling](#signal-handling)
* [Real world examples](#real-world-examples)

## Why?
PHP isn't a multithreaded language and neither can it handle (most) tasks asynchronous. Sure there is pthreads and pcntl but those are only usable in cli mode (or should only be used there). Using this library you are able to write taks which can be executed in parallel by the same or any other system.

## How does it work (The short way please)?
A job is scheduled via a task scheduler and gets written into a central message queue (MongoDB). All Queue nodes will get notified in (soft) realtime that a new job is available.
The queue node will forward the job via a internal systemv message queue to the worker manager. The worker manager decides if a new worker needs to be spawned.
At last, one worker will execute the task according the principle first come first serves. If no free slots are available the job will wait in the queue and get executed as soon as there is a free slot.
A job may be rescheduled if it failed. There are lots of more features available, continue reading.

## Requirements
* Posix system (Basically every linux)
* MongoDB server >= 3.6
* MongoDB replication set (May also be just a single MongoDB node)
* PHP >= 7.1
* PHP pcntl extension
* PHP posix extension
* PHP mongodb extension
* PHP sysvmsg extension

>**Note**: This library will only work on \*nix systems. There is no windows support and there will most likely never be.


## Download
The package is available at [packagist](https://packagist.org/packages/gyselroth/php-task-scheduler)

To install the package via composer execute:
```
composer require gyselroth/php-task-scheduler
```

## Changelog
A changelog is available [here](https://github.com/gyselroth/php-task-scheduler/blob/master/CHANGELOG.md).

## Contribute
We are glad that you would like to contribute to this project. Please follow the given [terms](https://github.com/gyselroth/php-task-scheduler/blob/master/CONTRIBUTING.md).

## Terms
You may encounter the follwing terms in this readme or elsewhere:

| Term | Class | Description |
| ------------------- | ------------------ | --- |
| Scheduler | `TaskScheduler\Scheduler` | The Scheduler is used to add jobs, query jobs, delete jobs and listen for events, it is the only component besides (different) jobs which is actually used in your main application.  |
| Job | `TaskScheduler\JobInterface`  | A job implementation is the actual task you want to execute. |
| Process | `TaskScheduler\Process` | You will receive a process after adding jobs, query jobs and so on, a process is basically an upperset of your job implementation. |
| Queue Node | `TaskScheduler\Queue` | Queue nodes handle the available jobs and forward them to the worker manager. |
| Worker Manager | `TaskScheduler\WorkerManager` | The worker managers job is to spawn workers which actually handle a job. Note: A worker manager itself is a fork from the queue node process.
| Worker | `TaskScheduler\Worker` | Workers are the ones which process a job from the queue and actually do your submitted work. |
| Worker Factory | `TaskScheduler\WorkerFactoryInterface` | A worker factory needs to be implemented by you, it will spawn the worker manager and new workers. |
| Cluster | - | A cluster is a set of multiple queue nodes. A cluster does not need to be configured in any way, you may start as many queue nodes as you want. |

## Install

If your app gets built using a docker container you must use at least the following build options:

```Dockerfile
FROM php:7.4
RUN docker-php-ext-install pcntl sysvmsg
RUN pecl install mongodb && docker-php-ext-enable mongodb pcntl sysvmsg
```

## Documentation

For a better understanding how this library works, we're going to implement a mail job. Of course you can implement any kind of job.

### Create job

It is quite easy to create a task, you just need to implement TaskScheduler\JobInterface.
In this example we're going to implement a job called MailJob which sends mail using zend-mail.

>**Note**: You can use TaskScheduler\AbstractJob to implement the required default methods by TaskScheduler\JobInterface.
The only thing then you need to implement is start() which does the actual job (sending mail).

```php
class MailJob extends TaskScheduler\AbstractJob
{
    /**
     * {@inheritdoc}
     */
    public function start(): bool
    {
        $transport = new Zend\Mail\Transport\Sendmail();
        $mail = Message::fromString($this->data);
        $this->transport->send($mail);

        return true;
    }
}
```

### Initialize scheduler

You need an instance of a MongoDB\Database and a Psr\Log\LoggerInterface compatible logger to initialize the scheduler.

```php
$mongodb = new MongoDB\Client('mongodb://localhost:27017');
$logger = new \A\Psr4\Compatible\Logger();
$scheduler = new TaskScheduler\Scheduler($mongodb->mydb, $logger);
```

### Spool job

Now let us create a mail and deploy it to the task scheduler which we have initialized right before:

```php
$mail = new Message();
$mail->setSubject('Hello...');
$mail->setBody('World');
$mail->setFrom('root@localhost', 'root');

$scheduler->addJob(MailJob::class, $mail->toString());
```

This is the whole magic, our scheduler now got its first job, awesome!


### Execute jobs

But now we need to execute those queued jobs.  
That's where the queue nodes come into play.
Those nodes listen in (soft) realtime for new jobs and will load balance those jobs.

#### Create worker factory

You will need to create your own worker node factory in your app namespace which gets called to spawn new child processes.
This factory gets called during a new fork is spawned. This means if it gets called, you are in a new process and you will need to bootstrap your application
from scratch (Or just the things you need for a worker).

>**Note**: Both a worker manager and a worker itself are spawned in own forks from the queue node process.

```
Queue node (TaskScheduler\Queue)
|
|-- Worker Manager (TaskScheduler\WorkerManager)
    |
    |-- Worker (TaskScheduler\Worker)
    |-- Worker (TaskScheduler\Worker)
    |-- Worker (TaskScheduler\Worker)
    |-- ...
```

For both a worker manager and a worker a new fork means you will need to bootstrap the class from scratch.

>**Note**: Theoretically you can reuse existing connections, objects and so on by setting those via the constructor of your worker factory since the factory gets initialized in main(). But this will likely lead to errors and strange app behaviours and is not supported.

For better understanding: if there is a configuration file where you have stored your configs like a MongoDB uri, in the factory you will need to parse this configuration again and create a new mongodb instance.
Or you may be using a PSR-11 container, the container needs to be created from scratch in the factory (A new dependency tree). You may pass an instance of a dic (compatible to Psr\Container\ContainerInterface) as fifth argument to TaskScheduler\Worker (or advanced worker manager options as third argument for a TaskScheduler\WorkerManager ([Advanced worker manager options](#advaced)). See more at [Using a DIC](#using-a-psr-11-dic-dependeny-injection-container)).

```php
class WorkerFactory extends TaskScheduler\WorkerFactoryInterface
{
    /**
     * {@inheritdoc}
     */
    public function buildWorker(MongoDB\BSON\ObjectId $id): TaskScheduler\Worker
    {
        $mongodb = new MongoDB\Client('mongodb://localhost:27017');
        $logger = new \A\Psr4\Compatible\Logger();
        $scheduler = new TaskScheduler\Scheduler($mongodb->mydb, $logger);

        return new TaskScheduler\Worker($id, $scheduler, $mongodb->mydb, $logger);
    }
    
    /**
     * {@inheritdoc}
     */
    public function buildManager(): TaskScheduler\WorkerManager
    {
        $logger = new \A\Psr4\Compatible\Logger();
        return new TaskScheduler\WorkerManager($this, $logger);
    }
}
```

#### Create queue node

Let us write a new queue node. The queue node must be started as a separate process!
You should provide an easy way to start such queue nodes, there are multiple ways to achieve this. The easiest way
is to just create a single php script which can be started via cli.


```php
$mongodb = new MongoDB\Client('mongodb://localhost:27017');
$logger = new \A\Psr4\Compatible\Logger();
$scheduler = new TaskScheduler\Scheduler($mongodb->mydb, $logger);
$worker_factory = My\App\WorkerFactory(); #An instance of our previously created worker factory
$queue = new TaskScheduler\Queue($scheduler, $mongodb, $worker_factory, $logger);
```

And then start the magic:

```php
$queue->process();
```

>**Note**: TaskScheduler\Queue::process() is a blocking call.


Our mail gets sent as soon as a queue node is running and started some workers.

Usually you want those nodes running at all times! They act like invisible execution nodes behind your app.

### Manage jobs

#### Get jobs
You may want to retrieve all scheduled jobs:

```php
$scheduler = new TaskScheduler\Scheduler($mongodb->mydb, $logger);
$scheduler->getJobs();
```

By default you will receive all jobs with the status:
* WAITING
* PROCESSING
* POSTPONED

You may pass an optional query to query specific jobs.

```php
$scheduler = new TaskScheduler\Scheduler($mongodb->mydb, $logger);
$jobs = $scheduler->getJobs([
    'status' => TaskScheduler\JobInterface::STATUS_DONE,
    '$or' => [
        ['class' => 'MyApp\\MyTask1'],
        ['class' => 'MyApp\\MyTask2'],
    ]
]);

foreach($jobs as $job) {
    echo $job->getId()." done\n";
}
```

#### Cancel job
It is possible to cancel jobs waiting in the queue as well as kill jobs which are actually running.
```php
$scheduler = new TaskScheduler\Scheduler($mongodb->mydb, $logger);
$scheduler->cancelJob(MongoDB\BSON\ObjectId $job_id);
```
If you cancel a job with the status PROCESSING, the job gets killed by force and my corrupt data. You have been warned.
(This is similar as the job ends with status TIMEOUT). The only difference is that a timeout job gets rescheduled if it has retry > 0 or has a configured interval.
A canceled job will not get rescheduled. You will need to create a new job manually for that.

#### Modify jobs
It is **not** possible to modify a scheduled job by design. You need to cancel the job and append a new one.

>**Note**: This is likely to be changed with v4 which will feature persistence for jobs.

#### Flush queue

While it is not possible to modify/remove jobs it is possible to flush the entire queue.
>**Note**: This is not meant to be called regularly. There may be a case where you need to flush all jobs because of an upgrade.
Running queue nodes will detect this and will listen for newly spooled jobs.


```php
$scheduler = new TaskScheduler\Scheduler($mongodb->mydb, $logger);
$scheduler->flush();
```

### Handling of failed jobs

A job is acknowledged as failed if the job throws an exception of any kind.
If we have a look at our mail job again, but this time it will throw an exception:

```php
class MailJob extends TaskScheduler\AbstractJob
{
    /**
     * {@inheritdoc}
     */
    public function start(): bool
    {
        $transport = new Zend\Mail\Transport\Sendmail();
        $mail = Message::fromString($this->data);
        $this->transport->send($mail);
        throw new \Exception('i am an exception');
        return true;
    }
}
```

This will lead to a FAILED job as soon as this job gets executed.

>**Note**: It does not matter if you return `true` or `false`, only an uncaught exception will result to a FAILED job, however you should always return `true`.

The scheduler has an integrated handling of failed jobs. You may specify to automatically reschedule a job if it failed.
The following will reschedule the job up to 5 times (If it ended with status FAILED) with an interval of 30s.

```php
$scheduler->addJob(MailJob::class, $mail->toString(), [
    TaskScheduler\Scheduler::OPTION_RETRY => 5,
    TaskScheduler\Scheduler::OPTION_RETRY_INTERVAL => 30,
]);
```

This will queue our mail to be executed in one hour from now and it will re-schedule the job up to three times if it fails with an interval of one minute.

### Alive ping and Job progress

TaskScheduler has built-in support to update the progress of a job from your job implementation.
By default a job starts at `0` (%) and ends with progress `100` (%). Note that the progress is floating number.
You may increase the progress made within your job.

**Important**:
Note that by default the scheduler takes a job after 30s as orphaned and reschedules it.
You may change the 30s globally during the Scheduler initialization or keep calling `->updateProgress()` within your task implementation.
Calling `updateProgress` with or without a progress acts like a keep alive ping for the scheduler and should be called in your task if it a long running task which contains a loop. If there is no loop you should still call this method in some form of intervals to keep your task alive.
Set a progress as percentage value is not required, if not set the task keeps beeing at 0% and set to 100% if finished.


Let us have a look how this works with a job which copies a file from a to b.

```php
class CopyFileJob extends TaskScheduler\AbstractJob
{
    /**
     * {@inheritdoc}
     */
    public function start(): bool
    {
        $source = $this->data['source'];
        $dest = $this->data['destination'];

        $size = filesize($source);
        $f = fopen($source, 'r');
        $t = fopen($dest, 'w');
        $read = 0;

        while($chunk = fread($f, 4096)) {
            $read += fwrite($t, $chunk);
            $this->updateProgress($read/$size*100);
        }
    }
}
```

The current progress may be available using the process interface:

```php
$scheduler = new TaskScheduler\Scheduler($mongodb->mydb, $logger);
$p = $scheduler->getJob(MongoDB\BSON\ObjectId('5b3cbc39e26cf26c6d0ede69'));
$p->getProgress();
```

>**Note** There is a rate limit for the progress updates which is by default 500ms. You may change the rate limit by configuring the `TaskScheduler::OPTION_PROGRESS_RATE_LIMIT` to something else and to 0
if you do not want a rate limit at all.

### Asynchronous programming

Have a look at this example:

```php
$scheduler = new TaskScheduler\Scheduler($mongodb->mydb, $logger);
$scheduler->addJob(MyTask::class, 'foobar')
  ->wait();
```

This will force main() (Your process) to wait until the task `MyTask::class` was executed. (Either with status DONE, FAILED, CANCELED, TIMEOUT).

Here is more complex example:
```php
$scheduler = new TaskScheduler\Scheduler($mongodb->mydb, $logger);
$stack = [];
$stack[] = $scheduler->addJob(MyTask::class, 'foobar');
$stack[] = $scheduler->addJob(MyTask::class, 'barfoo');
$stack[] = $scheduler->addJob(OtherTask::class, 'barefoot');

$scheduler->waitFor($stack);

//some other important stuff here
```

This will wait for all three jobs to be finished before continuing.

**Important note**:\
If you are programming in http mode (incoming http requests) and your app needs to deploy tasks it is good practice not to wait!.
Best practice is to return a [HTTP 202 code](https://httpstatuses.com/202) instead. If the client needs to know the result of those jobs you may return
the process id's and send a 2nd request which then waits and returns the status of those jobs or the client may get its results via a persistent connection or websockets.

```php
$scheduler = new TaskScheduler\Scheduler($mongodb->mydb, $logger);
$stack = $scheduler->getJobs([
    '_id' => ['$in' => $job_ids_from_http_request]
]);

$scheduler->waitFor(iterator_to_array($stack));

//do stuff
```

You may also intercept the wait if any process results in an exception:
```php
$scheduler = new TaskScheduler\Scheduler($mongodb->mydb, $logger);
$stack = [];
$stack[] = $scheduler->addJob(MyTask::class, 'foobar');
$stack[] = $scheduler->addJob(MyTask::class, 'barfoo');
$stack[] = $scheduler->addJob(OtherTask::class, 'barefoot');

try {
    $scheduler->waitFor($stack, Scheduler::OPTION_THROW_EXCEPTION);
} catch(\Exception $e) {
    //error handling
}
```

### Listen for events
You may bind to the scheduler and listen for any changes and do stuff :)

```php
$scheduler = new TaskScheduler\Scheduler($mongodb->mydb, $logger);
$jobs = $scheduler->listen(function(TaskScheduler\Process $process) {
    echo "status of ".$process->getId().' change to '.$process->getStatus();
});
```

It is also possible to filter such events, this example will only get notified for events occured in a specific job.
```php
$scheduler = new TaskScheduler\Scheduler($mongodb->mydb, $logger);
$jobs = $scheduler->listen(function(TaskScheduler\Process $process) {
    echo "status of ".$process->getId().' change to '.$process->getStatus();
}, [
    '_id' => new MongoDB\BSON\ObjectId('5b3cbc39e26cf26c6d0ede69')
]);
```

>**Note**: listen() is a blocking call, you may exit the listener and continue with main() if you return a boolean `true` in the listener callback.

### Bind events
Besides the simple listener method for the Scheduler you may bind event listeneres to your `TaskScheduler\Queue` and/or `TaskScheduler\Scheduler`.

For example:
```php
$scheduler = new TaskScheduler\Scheduler($mongodb->mydb, $logger);
$stack = [];
$stack[] = $scheduler->addJob(MyTask::class, 'foobar');
$stack[] = $scheduler->addJob(MyTask::class, 'barfoo');
$stack[] = $scheduler->addJob(OtherTask::class, 'barefoot');

$scheduler->on('waiting', function(League\Event\Event $e, TaskScheduler\Process $p) {
    echo 'job '.$p->getId().' is waiting';
})->on('done', function(League\Event\Event $e, TaskScheduler\Process $p) {
    echo 'job '.$p->getId().' is finished';
})->on('*', function(League\Event\Event $e, TaskScheduler\Process $p) {
    echo 'job '.$p->getId().' is '.$p->getStats();
});

$scheduler->waitFor($stack);
```

>**Note**: You need to to bind your listeneres before calling `Scheduler::waitFor()` since that is a synchronous blocking call.


You may bind listeneres to the same events in your queue nodes:

```php
$queue = new TaskScheduler\Queue($scheduler, $mongodb, $worker_factory, $logger);

$queue->on('timeout', function(League\Event\Event $e, TaskScheduler\Process $p) {
    echo 'job '.$p->getId().' is timed out';
})->on('*', function(League\Event\Event $e, TaskScheduler\Process $p) {
    echo 'job '.$p->getId().' is '.$p->getStats();
});

$queue->process();
```

>**Note**: You need to to bind your listeneres before calling `Queue::process()` since that is a synchronous blocking call.

#### Events

You may bind for the following events:

| Short  | Full | Scope | Description |
| --- | --- | --- | --- |
| `waiting`  | `taskscheduler.onWaiting`  | global | Triggers after a new job got added  |
| `postponed`  | `taskscheduler.onPostponed`  | global | Triggers after a job has been postponed |
| `processing`  | `taskscheduler.onProcessing`  | global | Triggers after a job started to execute  |
| `done`  | `taskscheduler.onDone`  | global | Triggers after a job finished successfully  |
| `failed`  | `taskscheduler.onFailed`  | global | Triggers after a job failed  |
| `timeout`  | `taskscheduler.onTimeout`  | global | Triggers after a job timed out  |
| `cancel`  | `taskscheduler.onCancel`  | global | Triggers after a job has been canceled  |
| `workerSpawn`  | `taskscheduler.onWorkerSpawn`  | queue node only | Triggers after a queue node spawned a new worker  |
| `workerKill`  | `taskscheduler.onWorkerKill`  | queue node only | Triggers after a worker stopped on a queue node  |

#### Custom event emitter

Under the hood both `TaskScheduler\Queue` and `TaskScheduler\Scheduler` use `League\Event\Emitter` as event emitter.
You may create both instances with your own Leage Event emitter instance:

```php
$emitter = new League\Event\Emitter();

//Queue
$queue = new TaskScheduler\Queue($scheduler, $mongodb, $worker_factory, $logger, $emitter);

//Scheduler
$scheduler = new TaskScheduler\Scheduler($mongodb->mydb, $logger, [], $emitter);
```

### Advanced job options
TaskScheduler\Scheduler::addJob()/TaskScheduler\Scheduler::addJobOnce() also accept a third option (options) which let you set more advanced options for the job:

| Option  | Default | Type | Description |
| --- | --- | --- | --- |
| `at`  | `0`  | int | Accepts a specific unix time which let you specify the time at which the job should be executed. The default (0) is immediately or better saying as soon as there is a free slot. |
| `interval`  | `0`  | int | You may specify a job interval (in seconds) which is usefully for jobs which need to be executed in a specific interval, for example cleaning a temporary directory. The default is `0` which means no interval at all, `-1` means execute the job immediately again (But be careful with `-1`, this could lead to huge cpu usage depending what job you're executing). Configuring `3600` means the job will get executed hourly. |
| `interval_reference`  | `end`  | string | You may specify if the interval refers to the `start` or the `end` of the previous job. The default is `end` which means the interval refers to the end time of the previous job. When you define `start` the interval refers to the start time of the previous job. This may be useful when a job has to run at specific times. |
| `retry`  | `0`  | int | Specifies a retry interval if the job fails to execute. The default is `0` which means do not retry. `2` for example means 2 retries. You may set `-1` for endless retries. |
| `retry_interval`  | `300`  | int | This options specifies the time (in seconds) between job retries. The default is `300` which is 5 minutes. Be careful with this option while `retry_interval` is `-1`, you may ending up with a failure loop.  |
| `force_spawn`  | `false`  | bool | You may specify `true` for this option to spawn a new worker only for this task. Note: This option ignores the max_children value of `TaskScheduler\WorkerManager`, which means this worker always gets spawned.  It makes perfectly sense for jobs which make blocking calls, for example a listener which listens for local filesystem changes (inotify). A job with this enabled option should only consume as little cpu/memory as possible! |
| `timeout`  | `0`  | int | Specify a timeout in seconds which will terminate the job after the given time has passed by force. The default `0` means no timeout at all. A timeout job will get rescheduled if retry is not `0` and will marked as timed out.  |
| `id`  | `null`  | `MongoDB\BSON\ObjectId` | Specify a job id manually.  |
| `ignore_data`  | `false`  | bool | Only useful if set in a addJobOnce() call. If `true` the scheduler does not compare the jobs data to decide if a job needs to get rescheduled.  |


>**Note**: Be careful with timeouts since it will kill your running job by force. You have been warned. You shall always use a native timeout in a function if supported.

Let us add our mail job example again with some custom options:

>**Note**: We are using the OPTION_ constansts here, you may also just use the names documented above.

```php
$mongodb = new MongoDB\Client('mongodb://localhost:27017');
$logger = new \A\Psr4\Compatible\Logger();
$scheduler = new TaskScheduler\Scheduler($mongodb->mydb, $logger);

$mail = new Message();
$mail->setSubject('Hello...');
$mail->setBody('World');
$mail->setFrom('root@localhost', 'root');

$scheduler->addJob(MailJob::class, $mail->toString(), [
    TaskScheduler\Scheduler::OPTION_AT => time()+3600,
    TaskScheduler\Scheduler::OPTION_RETRY => 3,
    TaskScheduler\Scheduler::OPTION_RETRY_INTERVAL => 60,
]);
```

This will queue our mail to be executed in one hour from now and it will re-schedule the job up to three times if it fails with an interval of one minute.

### Add job if not exists
What you also can do is adding the job only if it has not been queued yet.
Instead using `addJob()` you can use `addJobOnce()`, the scheduler then verifies if it got the same job already queued. If not, the job gets added.
The scheduler compares the type of job (`MailJob` in this case) and the data submitted (`$mail->toString()` in this case).

>**Note**: The job gets rescheduled if options get changed.

```php
$scheduler->addJobOnce(MailJob::class, $mail->toString(), [
    TaskScheduler\Scheduler::OPTION_AT => time()+3600,
    TaskScheduler\Scheduler::OPTION_RETRY => 3,
]);
```
By default `TaskScheduler\Scheduler::addJobOnce()` does compare the job class, the submitted data and the process status (either PROCESSING, WAITING or POSTPONED).
If you do not want to check the data, you may set `TaskScheduler\Scheduler::OPTION_IGNORE_DATA` to `true`. This will tell the scheduler to only reschedule the job of the given class
if the data changed. This is quite useful if a job of the given class must only be queued once.


>**Note**: This option does not make sense in the mail example we're using here. A mail can have different content. But it may happen that you have job which clears a temporary storage every 24h:
```php
$scheduler->addJobOnce(MyApp\CleanTemp::class, ['max_age' => 3600], [
    TaskScheduler\Scheduler::OPTION_IGNORE_DATA => true,
    TaskScheduler\Scheduler::OPTION_INTERVAL => 60*60*24,
]);
```

If max_age changes, the old job gets canceled and a new one gets queued. If `TaskScheduler\Scheduler::OPTION_IGNORE_DATA` is not set here we will end up with two jobs of the type `MyApp\CleanTemp::class`.
```php
$scheduler->addJobOnce(MyApp\CleanTemp::class, ['max_age' => 1800], [
    TaskScheduler\Scheduler::OPTION_IGNORE_DATA => true,
    TaskScheduler\Scheduler::OPTION_INTERVAL => 60*60*24,
]);
```

Of course it is also possible to query such job manually, cancel them and reschedule. This will achieve the same as above:
```php
$jobs = $scheduler->getJobs([
    'class' => MyApp\CleanTemp::class,
    'status' => ['$lte' => TaskScheduler\JobInterface::STATUS_PROCESSING]
]);

foreach($jobs as $job) {
    $scheduler->cancelJob($job->getId());
}

$scheduler->addJob(MyApp\CleanTemp::class, ['max_age' => 1800], [
    TaskScheduler\Scheduler::OPTION_INTERVAL => 60*60*24,
]);
```

### Advanced scheduler options

You may set those job options as global defaults for the whole scheduler.
Custom options and defaults can be set for jobs during initialization or by calling Scheduler::setOptions().

```php
$mongodb = new MongoDB\Client('mongodb://localhost:27017');
$logger = new \A\Psr4\Compatible\Logger();
$scheduler = new TaskScheduler\Scheduler($mongodb->mydb, $logger, null, [
    TaskScheduler\Scheduler::OPTION_JOB_QUEUE_SIZE => 1000000000,
    TaskScheduler\Scheduler::OPTION_EVENT_QUEUE_SIZE => 5000000000,
    TaskScheduler\Scheduler::OPTION_DEFAULT_RETRY => 3
]);
```

You may also change those options afterwards:
```php
$scheduler->setOptions([
    TaskScheduler\Scheduler::OPTION_DEFAULT_RETRY => 2
]);
```

>**Note**: Changing default job options will not affect any existing jobs.


| Name  | Default | Type | Description |
| --- | --- | --- | --- |
| `job_queue`  | `taskscheduler.jobs`  | string | The MongoDB collection which acts as job message queue. |
| `job_queue_size`  | `1000000`  | int | The maximum size in bytes of the job collection, if reached the first jobs get overwritten by new ones. |
| `event_queue`  | `taskscheduler.events`  | string | The MongoDB collection which acts as event message queue. |
| `event_queue_size`  | `5000000`  | int | The maximum size in bytes of the event collection, if reached the first events get overwritten by new ones. This value should usually be 5 times bigger than the value of `job_queue_size` since a job can have more events. |
| `default_at`  | `null`  | ?int | Define a default execution time for **all** jobs. This relates only for newly added jobs. The default is immediately or better saying as soon as there is a free slot. |
| `default_interval`  | `0`  | int | Define a default interval for **all** jobs. This relates only for newly added jobs. The default is `0` which means no interval at all. |
| `default_interval_reference`  | `end`  | string | Define if the interval refers to the `start` or the `end` of the previous job. |
| `default_retry`  | `0`  | int | Define a default retry interval for **all** jobs. This relates only for newly added jobs. There are no retries by default for failed jobs. |
| `default_retry_interval`  | `300`  | int | This options specifies the time (in seconds) between job retries. This relates only for newly added jobs. The default is `300` which is 5 minutes. |
| `default_timeout`  | `0`  | int | Specify a default timeout for all jobs. This relates only for newly added jobs. Per default there is no timeout at all. |


>**Note**: It is important to choose a queue size (job_queue_size and event_queue_size) which fits into your setup.


### Advanced worker manager options

While you already know, that you need a worker factory to spawn the worker manager, you may specify advanced options for it!
Here is our worker [factory again](#create-worker-factory), but this time we specify some more options:

```php
class WorkerFactory extends TaskScheduler\WorkerFactoryInterface
{
    /**
     * {@inheritdoc}
     */
    public function buildWorker(MongoDB\BSON\ObjectId $id): TaskScheduler\Worker
    {
        $mongodb = new MongoDB\Client('mongodb://localhost:27017');
        $logger = new \A\Psr4\Compatible\Logger();
        $scheduler = new TaskScheduler\Scheduler($mongodb->mydb, $logger);

        return new TaskScheduler\Worker($id, $scheduler, $mongodb->mydb, $logger);
    }
    
    /**
     * {@inheritdoc}
     */
    public function buildManager(): TaskScheduler\WorkerManager
    {
        $logger = new \A\Psr4\Compatible\Logger();
        return new TaskScheduler\WorkerManager($this, $logger, [
            TaskScheduler\WorkerManager::OPTION_MIN_CHILDREN => 10,
            TaskScheduler\WorkerManager::OPTION_PM => 'static' 
        ]);
    }
}
```

Worker handling is done by specifying the option `pm` while dynamically spawning workers is the default mode.

| Name  | Default | Type | Description |
| --- | --- | --- | --- |
| `pm`  | `dynamic`  | string | You may change the way how fork handling is done. There are three modes: dynamic, static, ondemand. |
| `min_children`  | `1`  | int | The minimum number of child processes. |
| `max_children`  | `2`  | int | The maximum number of child processes. |

Process management modes (`pm`):

* dynamic (start min_children forks at startup and dynamically create new children if required until max_children is reached)
* static (start min_children nodes, (max_children is ignored))
* ondemand (Do not start any children at startup (min_children is ignored), bootstrap a worker for each job but no more than max_children. After a job is done (Or failed, canceled, timeout), the worker dies.

The default is `dynamic`. Usually `dynamic` makes sense. You may need `static` in a container provisioned world whereas the number of queue nodes is determined
from the number of outstanding jobs. For example you may be using [Kubernetes autoscaling](https://cloud.google.com/kubernetes-engine/docs/tutorials/custom-metrics-autoscaling).

>**Note**: The number of actual child processes can be higher if jobs are scheduled with the option Scheduler::OPTION_FORCE_SPAWN.

### Using a PSR-11 DIC
Optionally one can pass a Psr\Container\ContainerInterface to the worker nodes which then get called to create job instances.
You probably already get it, but here is the worker [factory again](#create-worker-factory). This time it passes an instance of a PSR-11 container to worker nodes.
And if you already using a container it makes perfectly sense to request the manager from it. (Of course you may also request a worker instances from it if your container implementation supports
parameters at runtime (The worker id). Note: This will be an incompatible container implementation from the [PSR-11 specification](https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-11-container.md).)

```php
class WorkerFactory extends TaskScheduler\WorkerFactoryInterface
{
    /**
     * {@inheritdoc}
     */
    public function build(MongoDB\BSON\ObjectId $id): TaskScheduler\Worker
    {
        $mongodb = new MongoDB\Client('mongodb://localhost:27017');
        $logger = new \A\Psr4\Compatible\Logger();
        $scheduler = new TaskScheduler\Scheduler($mongodb->mydb, $logger);
        $dic = new \A\Psr11\Compatible\Dic();

        return new TaskScheduler\Worker($id, $scheduler, $mongodb->mydb, $logger, $dic);
    }
    
    /**
     * {@inheritdoc}
     */
    public function buildManager(): TaskScheduler\WorkerManager
    {
        $dic = new \A\Psr11\Compatible\Dic();
        return $dic->get(TaskScheduler\WorkerManager::class);
    }
}
```

### Signal handling

Terminating queue nodes is possible of course. They even manage to reschedule running jobs. You just need to send a SIGTERM to the process. The queue node then will transmit this the worker manager while the worker manager will send it to all running workers and they
will save their state and nicely exit. A worker also saves its state if the worker process directly receives a SIGTERM.
If a SIGKILL was used to terminate the queue node (or worker) the state can not be saved and you might get zombie jobs (Jobs with the state PROCESSING but no worker will actually process those jobs).
No good sysadmin will terminate running jobs by using SIGKILL, it is not acceptable and may only be used if you know what you are doing.

You should as well avoid using never ending blocking functions in your job, php can't handle signals if you do that.


## Real world examples

| Project  | Description |
| --- | --- |
| [balloon](https://github.com/gyselroth/balloon)  | balloon is a high performance cloud server. It makes use of this library to deploy all kind of jobs (create previews, scan files, upload to elasticsearch, sending mails, converting documents, clean temporary storage, clean trash, ...). |
| [tubee](https://github.com/gyselroth/tubee)  | tubee is data management engine and makes use of this library to execute its sync jobs. |

Add your project here, a PR will be most welcome.
