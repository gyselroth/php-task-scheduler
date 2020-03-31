## v3 => v4

### MongoDB version

The scheduler v4 makes use of MongoDB changestreams instead capped collections. Which also means the MongoDB version 
must be at least v3.6. Also MongoDB must be deployed as a replication set.
If your deployment can not meet these new requirements you have to continue to use v3.

## API
The API v4 is fully compatible with the v3 API. There is no breaking change.

## Persistency
The new taskscheduler collection is not a capped collection anymore. Therefore all jobs will be kept forever unless you 
do not integrate any mechanism to cleanup old records. By design this is left to implementators. 
For example this may be done using a MongoDB index with autoremoval support.

## Cleanup collections
The pre v4 collections `taskscheduler.jobs` and `taskscheduler.events` may be removed. There is no inbuilt mechanism for this.
Also if required you need to write a mechanism to migrate jobs from the legacy collection to the new `takscheduler` collection. Do so using the scheduler api if any task migration is required.

## Job alive ping
v4 comes with a feature to automatically reschedule orphaned jobs. An orphaned job is when a job is flagged with the processing status but is not handled by any worker. This may happen if workers get killed by `sigkill` or other exceptions.
By default if a running job did not notify the scheduler within 30s the job get killed and reset to waiting.
Note that the 30s may be changed to another value during the initializer of the scheduler.
To keep the API compatibility the alive ping is made by calling `updateProgress(float $progress_percentage)` which is maybe already implemented in your tasks. 
If no progress percantage should be updated or it is just not important in your case you may just leave that parameter and just call `updateProgress()`.

## v1/v2 => v3

### Implementation of TaskScheduler\Process

If you do not use the return value of `TaskScheduler\Scheduler::addJob` or `Scheduler::addJobOnce` the upgrade in user space will be fully compatible.
One major change is, that you will receive an instance of `TaskScheduler\Process` instead just the process id from those methods.

To retrieve the job id, you will need to do the following in v3 (Note the getId() call):

```
$job_id = $taskscheduler->addJob(MyJob::class, ['foo' => 'bar'])->getId();
```

### New queue name

The job structure is different from v2 and v1. Meaning a queued task from v2 will break compatibility in v3. 
By default v3 will create new queues (`taskscheudler.jobs` and `taskscheduler.events`). You have to make sure that your old v2 queue
has no job left before upgrade. (Those will not get upgraded automatically).
You may change the new queue names in the scheduler options.

### pcntl dependency

v3 requires the php pcntl dependency to handle forks. PHP must be compiled with ` --enable-pcntl` which should be the case for most distributed php packages. 
If your app runs on docker, you may easly add `docker-php-ext-install pcntl && docker-php-ext-enable pcntl` to your Dockerfile.

### sysvmsg dependency

v3 requires a systemv message queue besides the mongodb queue to communicate with forks. PHP must be compiled with `--enable-sysvmsg` which should be the case for most distributed php packages.
If you build your app on docker, you may easly add `docker-php-ext-install sysvmsg && docker-php-ext-enable sysvmsg` to Dockerfile.

### Run once (cron)

If you did use TaskScheduler\Queue::processOnce before, this wont be possible anymore. There is no support for running jobs once anymore (via cron for example). You will likely need to migrate to TaskScheduler\Queue::process which acts as a daemon.

### Worker factory

v3 does require a worker factory to initialize a queue node. Please see the documentation [here](README.md#execute-jobs).
