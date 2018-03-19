# Task Scheduler 

[![Build Status](https://travis-ci.org/gyselroth/mongodb-php-task-scheduler.svg?branch=master)](https://travis-ci.org/gyselroth/mongodb-php-task-scheduler)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/gyselroth/mongodb-php-task-scheduler/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/gyselroth/mongodb-php-task-scheduler/?branch=master)
[![Code Coverage](https://scrutinizer-ci.com/g/gyselroth/mongodb-php-task-scheduler/badges/coverage.png?b=master)](https://scrutinizer-ci.com/g/gyselroth/mongodb-php-task-scheduler/?branch=master)
[![Latest Stable Version](https://img.shields.io/packagist/v/gyselroth/mongodb-php-task-scheduler.svg)](https://packagist.org/packages/gyselroth/mongodb-php-task-scheduler)
[![GitHub release](https://img.shields.io/github/release/gyselroth/mongodb-php-task-scheduler.svg)](https://github.com/gyselroth/mongodb-php-task-scheduler/releases)
[![GitHub license](https://img.shields.io/badge/license-MIT-blue.svg)](https://raw.githubusercontent.com/gyselroth/mongodb-php-task-scheduler/master/LICENSE)

## Description
Asynchronous task scheduler for PHP based on MongoDB. Execute asynchronous tasks such as sending mail, syncing stuff, generate documents and much more easily.
You can implement a daemon which executes jobs and listens in real time for newly added jobs.
This library has also in-built support for clustered systems. You can start up multiple worker nodes and they will split the available jobs with the principal first comes first serves. It is also possible to schedule jobs at a certain time or with an endless interval as well as rescheduling if a job fails.

# Table of Contents
  * [Description](#description)
  * [Features](#features)
  * [Why?](#why)
  * [Requirements](#requirements)
  * [Download](#download)
  * [Changelog](#changelog)
  * [Contribute](#contribute)
  * [Documentation](#documentation)
    * [Create job](#create-job)
    * [Initialize scheduler](#initialize-scheduler)
    * [Create job queue](#create-job-queue)
    * [Create a job (mail example)](#Create-a-job-mail-example)
    * [Execute jobs](#execute-jobs)
    * [Create daemon](#create-daemon)
    * [Alternative way via cron](#alternative-way-via-cron)
    * [Advanced job options](#advanced-job-options)
    * [Add job only once](#add-job-only-once)
    * [Advanced default/initialization options](#advanced-defaultinitialization-options)
    * [Using a DIC (dependeny injection container)](#using-a-dic-dependeny-injection-container)
    * [Manage jobs](#manage-jobs)
    * [Get jobs](#get-jobs)
    * [Cancel job](#cancel-job)
    * [Modify job](#modify-job)

## Features

* TaskScheduler with queue support
* Schedule tasks at specific times
* Cluster support
* Retry and intervals
* Job management
* Easy handling of asynchronous tasks

## Why?
PHP isn't a multithreaded language and neither can it handle (most) tasks asynchronously. Sure there is pthreads (Which is also planned to be implemented) but it is only usable in cli mode.
This library helps you implementing jobs which are later (or as soon as there are free slots) executed by another process.

## Requirements
The library is only >= PHP7.1 compatible and requires a MongoDB server >= 2.2.

## Download
The package is available at [packagist](https://packagist.org/packages/gyselroth/mongodb-php-task-scheduler)

To install the package via composer execute:
```
composer require gyselroth/mongodb-php-task-scheduler
```

## Changelog
A changelog is available [here](https://github.com/gyselroth/mongodb-php-task-scheduler/blob/master/CHANGELOG.md).

## Contribute
We are glad that you would like to contribute to this project. Please follow the given [terms](https://github.com/gyselroth/mongodb-php-task-scheduler/blob/master/CONTRIBUTING.md).

## Documentation

For a better understanding how this library works, we're going to implement a mail job. Of course you can implement any kind of jobs, multiple jobs, 
multiple workers, whatever you like!

### Create job

It is quite easy to create as task, you just need to implement TaskScheduler\JobInterface. 
In this example we're going to implement a job called MailJob which sends mail via zend-mail.

**Note**: You can use TaskScheduler\AbstractJob to implement the required default methods by TaskScheduler\JobInterface.
The only thing then you need to implement is start() which does the actual job (sending mail).

```php
class MailJob extends AbstractJob
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

### Create a job (mail example)

Now let us create a mail and add it to our task scheduler which we have initialized right before:

```php
$mail = new Message();
$mail->setSubject('Hello...');
$mail->setBody('World');
$mail->setFrom('root@localhost', 'root');

$scheduler->addJob(MailJob::class, $mail->toString());
```

This is the whole magic, our scheduler now got its first job, awesome!


### Execute jobs

But now we need to execute those queued jobs. This can usualy be achieved in two ways, either add a cron job or the **recommended** way as 
a unix daemon.
The big advantage of this library comes into play if you execute job workers as daemons (The possibility to execute via cron is only to support legacy applications).
The workers listen in real time for new jobs and they will load balance those jobs. 
The only thing required to achieve that is to spin up multiple daemons (Of course you can also start just one).

To handle the job queue and execute task you need `TaskScheduler::Queue`.

#### Create daemon

A unix daemaeon, way too complicated. No actually not, it is quite easy. Let us create a **separate** script beside our main app.
Again we first need to create an instance of our task scheduler:

```php
$mongodb = new MongoDB\Client('mongodb://localhost:27017');
$logger = new \A\Psr4\Compatible\Logger();
$scheduler = new TaskScheduler\Scheduler($mongodb->mydb, $logger);
$queue = new TaskScheduler\Queue($scheduler, $mongodb, $logger);
```

And then start the magic:

```php
$queue->process();
```

Let us call it daemon.php and start it:
```bash
php daemon.php &
```

Our daemon now executes the scheduled task and listens for new jobs in real time.


#### Alternative way via cron

It is recommended to execute tasks via a daemon but alternatively you can execute tasks via cron as well:

```php
$mongodb = new MongoDB\Client('mongodb://localhost:27017');
$logger = new \A\Psr4\Compatible\Logger();
$queue = new TaskScheduler\Queue($scheduler, $mongodb, $logger);
$queue->processOnce();
```

Call it cron.php and add it to cron:
```bash
echo "* * * * * /usr/bin/php /path/to/cron.php" >> /var/spool/cron/crontabs/$USER
```

### Advanced job options
TaskScheduler\Scheduler::addJob() also accepts a third option (options) which let you append more advanced options for the scheduler:

**at**

Accepts a specific unix time which let you specify the time at which the job should be executed.
The default is immediatly or better saying as soon as there is a free slot.

**interval**

You can also specify a job interval (in secconds) which is usefuly for jobs which need to be executed in a specific interval, for example cleaning a temporary directory.
The default is `-1` which means no interval at all, `0` would mean execute the job immediatly again (But be careful with `0`, this could lead to huge cpu usage depending what job you're executing).
Configuring `3600` would mean the job will be executed hourly.

**retry**

You can configure a retry interval if the job fails to execute. The default is `0` which means do not retry.

**retry_interval**

This options specifies the time (in secconds) between job retries. The default is `300` which is 5 minutes.


Let us add our mail job example with some custom options:
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

This will queue our mail to be executed in one hour from now and it will re-schedule the job up to three times if it fails.

### Add job only once
What you also can do is adding the job only if it has not been queued yet.
Instead using `addJob()` you can use `addJobOnce()` the scheduler then checks if got the same job already queued, it not the job gets added.
The scheduler compares the type of job (`MailJob` in this case) and the data submitted (`$mail->toString()` in this case).

```php
$scheduler->addJobOnce(MailJob::class, $mail->toString(), [
    TaskScheduler\Scheduler::OPTION_AT => time()+3600,
    TaskScheduler\Scheduler::OPTION_RETRY => 3,
]);
```

### Advanced default/initialization options

Custom options and defaults can be set for jobs during initialization or if you call setOptions().

```php
$mongodb = new MongoDB\Client('mongodb://localhost:27017');
$logger = new \A\Psr4\Compatible\Logger();
$scheduler = new TaskScheduler\Scheduler($mongodb->mydb, $logger, null, [
    'collection_name' => 'jobs',
    'queue_size' => 10000000,
    'default_retry' => 3
]);

$scheduler->setOptions([
    'default_retry' => 2
]);
```

**collection_name**

You can specifiy a different collection for the job queue. The default is `queue`.

**queu_size**
The queue size. This is only used during creating the job queue and has no impact later. The default is `100000`.

**default_at**

Define a default execution time for **all** jobs. This relates only for newly added jobs.
The default is immediatly or better saying as soon as there is a free slot.

**default_interval**

Define a default interval for **all** jobs. This relates only for newly added jobs.
The default is `-1` which means no interval at all.

**default_retry**

Define a default retry interval for **all** jobs. This relates only for newly added jobs.
There are now retries by default for failed jobs (The default is `0`).

**default_retry_interval**
This options specifies the time (in secconds) between job retries. This relates only for newly added jobs.
The default is `300` which is 5 minutes.

### Using a DIC (dependeny injection container)
Optionally one can pass a Psr\Container\ContainerInterface to the scheduler which gets used to create job instances.

```php
$mongodb = new MongoDB\Client('mongodb://localhost:27017');
$logger = new \A\Psr4\Compatible\Logger();
$dic = new \A\Psr11\Compatible\Container();
$scheduler = new TaskScheduler\Scheduler($mongodb->mydb, $logger);
$queue = new TaskScheduler\Queue($scheduler, $mongodb, $logger, $dic);
```

If a container is set, the scheduler will request job instances through the dic.

### Manage jobs

### Get jobs
You may want to retrieve all scheduled jobs:

```php
$scheduler = new TaskScheduler\Scheduler($mongodb->mydb, $logger);
$scheduler->getJobs();
```

By default you will receive all jobs with status:
* Queue::STATUS_WAITING,
* Queue::STATUS_PROCESSING
* Queue::STATUS_POSTPONED

You may pass an optional argument filter as an array which contains all status you want to retrieve.
You can also request a single job by calling `Scheduler::getJob(MongoDB\BSON\ObjectId $job_id)`.

### Cancel job
You are able to cancel a scheduled job by passing the job id to the scheduler:
```php
$scheduler = new TaskScheduler\Scheduler($mongodb->mydb, $logger);
$scheduler->cancelJob(MongoDB\BSON\ObjectId $job_id);
```

It is **not** possible to cancel running jobs (jobs with status Queue::STATUS_PROCESSING).

### Modify jobs
It is **not** possible to modify a scheduled job by design. You need to cancel the job and append a new one.
