## 3.3.0
**Maintainer**: Sandro Aebischer <aebischer@gyselroth.com>\
**Date**: Tue July 05 15:44:00 CET 2021

### Features
* Added option to define if job interval refers to start or end of previous job

## 3.2.2
**Maintainer**: Raffael Sahli <sahli@gyselroth.com>\
**Date**: Thu Dec 05 09:38:31 CET 2019

### Bugfixes
* Set progress always to 100.0 after a job has been finished
* Fixes build exit worker


## 3.2.1
**Maintainer**: Raffael Sahli <sahli@gyselroth.com>\
**Date**: Wed Dec 04 09:38:31 CET 2019

### Bugfixes
* Fixes event bindings via scheduler #26
* Fixes progress rate limit seconds => miliseconds


## 3.2.0
**Maintainer**: Raffael Sahli <sahli@gyselroth.com>\
**Date**: Tue Dec 03 16:50:33 CET 2019

### Features
* Add event bindings in the Process handler #26
* Add event callback bindings to wait(), waitFor() #28
* Progress support #29


## 3.1.0
**Maintainer**: Raffael Sahli <sahli@gyselroth.com>\
**Date**: Mon Mar 25 16:14:33 CET 2019

* [FIX] Killed workers do not get restarted if no new jobs are added #21
* [CHANGE] Slow "Process::wait()" when dealing with many jobs (Scheduler::waitFor($stack)) #20
* [CHANGE] Events are now completely processed before checking for any new jobs in the queue node


## 3.0.2
**Maintainer**: Raffael Sahli <sahli@gyselroth.com>\
**Date**: Mon Jan 28 12:18:34 CET 2019

* [CHANGE] Set intial datetime (not 0), writer worker caught exception: 10003 Cannot change the size of a document in a capped collection #15


## 3.0.1
**Maintainer**: Raffael Sahli <sahli@gyselroth.com>\
**Date**: Thu Jan 25 17:29:34 CET 2019

* [FIX] Catch \Throwable insteadof \Exception #13


## 3.0.0
**Maintainer**: Raffael Sahli <sahli@gyselroth.com>\
**Date**: Mon Nov 19 17:24:34 CET 2018

* [FEATURE] Added Scheduler::flush to flush the entire queue
* [FIX] fixed event queue creation during queue init


## 3.0.0-beta9
**Maintainer**: Raffael Sahli <sahli@gyselroth.com>\
**Date**: Tue Sept 25 14:24:34 CEST 2018

* [CHANGE] Implement a systemv message queue to bootstrap workers from a separate fork #6
* [CHANGE] Since #6 is implemented, a the TaskScheduler\WorkerFactoryInterface requires a buildWorker() and a buildManager() implementation
* [CHANGE] Removed Scheduler::OPTION_MAX_CHILDREN, replaced it with Scheduler::OPTION_FORCE_SPAWN
* [CHANGE] A worker manager configured as ondemand will no spawn a worker for each task


## 3.0.0-beta8
**Maintainer**: Raffael Sahli <sahli@gyselroth.com>\
**Date**: Tue Sept 25 14:24:34 CEST 2018

* [FIX] fixed addJobOnce compare submited options
* [FIX] do not spawn new worker if queue is only aware of fewer jobs than the current number of forks and OPTION_IGNORE_MAX_CHILDREN is requested
* [FIX] added safety zombie job check after a worker exits


## 3.0.0-beta7
**Maintainer**: Raffael Sahli <sahli@gyselroth.com>\
**Date**: Mon Sept 24 15:24:34 CEST 2018

* [FIX] Locally queued job gets rescheduled if was previously overwritten by the capped collection size limit
* [FIX] Fixed retry_interval with timeout jobs


## 3.0.0-beta6
**Maintainer**: Raffael Sahli <sahli@gyselroth.com>\
**Date**: Fri Sept 14 13:24:34 CEST 2018

* [CHANGE] Scheduler::cancelJob() does not throw an exception of Type JobNotFoundException if the job is already canceled
* [FEATURE] Added option Scheduler::OPTION_IGNORE_DATA to Scheduler::addJobOnce()
* [CHANGE] Removed UTCDateTime conversion from unix ts for option TaskScheduler\Scheduler::OPTION_AT


## 3.0.0-beta5
**Maintainer**: Raffael Sahli <sahli@gyselroth.com>\
**Date**: Mon Sept 10 12:22:24 CEST 2018

* [FIX] fix proper exit of worker nodes


## 3.0.0-beta4
**Maintainer**: Raffael Sahli <sahli@gyselroth.com>\
**Date**: Mon Sept 10 12:22:24 CEST 2018

* [FIX] fix proper exit of queue nodes
* [FIX] Do not log exception if a locally queued node is already queued during worker shutdown


## 3.0.0-beta3
**Maintainer**: Raffael Sahli <sahli@gyselroth.com>\
**Date**: Fri Sep 07 14:47:34 CEST 2018

* [FIX] Fixed concurrent Scheduler::addJobOnce() requests
* [CHANGE] 0 does now disable retry and interval, -1 sets both to endless
* [CHANGE] Added upgrade guide


## 3.0.0-beta2
**Maintainer**: Raffael Sahli <sahli@gyselroth.com>\
**Date**: Wed Sep 05 16:47:34 CEST 2018

* [FIX] Fixed naming of the collections
* [CHANGE] Using awaitData cursors now instead just tailable which will have a big impact on cpu usage
* [FIX] Cancelling a job which is out of the queue does now work as well


* [FEATURE] Possibility to timeout jobs
## 3.0.0-beta1
**Maintainer**: Raffael Sahli <sahli@gyselroth.com>\
**Date**: Thu Aug 30 11:47:34 CEST 2018

* [FEATURE] Possibility to timeout jobs
* [CHANGE] Process handling changed (breaking!), new Process class which gets returned and can be called for options
* [FEATURE] Possibility to wait for job execution
* [FEATURE] Possibility to listen for any events
* [FEATURE] Event (and exception) log
* [FEATURE] Abort running jobs


## 3.0.0-alpha1
**Maintainer**: Raffael Sahli <sahli@gyselroth.com>\
**Date**: Mon Aug 13 16:50:34 CEST 2018

* [FIX] Added pcntl requirement to composer.json
* [FEATURE] Implemented forks, meaning a the main process (Queue::class) is the fork handler and will bootstrap new child processes (@see README.md to see how to use v3.0.0)
* [CHANGE] Queue::processOnce() is not available anymore, Queue::process() is the only entrypoint and blocking process


## 2.0.5
**Maintainer**: Raffael Sahli <sahli@gyselroth.com>\
**Date**: Thu Jul 19 11:02:34 CEST 2018

* [CHANGE] Scheduler::cancelJob() not throws Exception\JobNotFound if job was not found
* [FIX] Fixed driver version (mongodb >= 1.5.0) support for ServerException along ConnectionException to create collection if not propperly setup
* [FIX] Use collection_name from options instead static queue in getCursor()
* [CHANGE] Extended phpstan level to 5


## 2.0.4
**Maintainer**: Raffael Sahli <sahli@gyselroth.com>\
**Date**: Thu Jul 19 11:02:34 CEST 2018

* [FEATURE] Get current job id with getId() in a JobInterface implementation 


## 2.0.3
**Maintainer**: Raffael Sahli <sahli@gyselroth.com>\
**Date**: Thu Jun 15 10:18:02 CEST 2018

* [FIX] fixed addJobOnce if options change the job gets rescheduled


## 1.0.3
**Maintainer**: Raffael Sahli <sahli@gyselroth.com>\
**Date**: Thu Jun 15 10:18:02 CEST 2018

* [FIX] fixed addJobOnce if options change the job gets rescheduled


## 2.0.2
**Maintainer**: Raffael Sahli <sahli@gyselroth.com>\
**Date**: Wed Mar 28 09:28:23 CET 2018

* [FIX] fixed TaskScheduler\Queue::cleanup, cannot access protected method TaskScheduler\Queue::cleanup()
* [CHANGE] code improvements, added new tests 


## 2.0.1
**Maintainer**: Raffael Sahli <sahli@gyselroth.com>\
**Date**: Tue Mar 20 11:00:22 CET 2018

* [CHANGE] queue collection gets now automatically created, even if the queue gets removed during processing
* [FEATURE] there is no need anymore to create the queue collection, it is handled automatically by TaskScheduler\Queue
* [FEATURE] retry can now be set to 0 and the job gets rescheduled if failed endlessly according to interval. -1 (Default is reserved for no retry)
* [FEATURE] can now handle process signals (SIGTERM, SIGINT) and reschedule current job before exit


## 2.0.0
**Maintainer**: Raffael Sahli <sahli@gyselroth.com>\
**Date**: Wed Mar 14 15:55:22 CET 2018

* [!Breaker] Split Async::class into Queue::class and Scheduler::class
* [FEATURE] Abondend timestamp field and added created, started and ended timestamps


## 1.0.3
**Maintainer**: Raffael Sahli <sahli@gyselroth.com>\
**Date**: Thu Jun 15 10:18:02 CEST 2018

* [FIX] fixed addJobOnce if options change the job gets rescheduled


## 1.0.2
**Maintainer**: Raffael Sahli <sahli@gyselroth.com>\
**Date**: Thu Feb 15 08:34:01 CET 2018

* [FIX] fixed typeMap from BSONDocument to array for all getter
* [FIX] fixed "Uncaught MongoDB\Driver\Exception\RuntimeException: The cursor is invalid or has expired" in certain cases
* [FIX] Job with interval gets not rescheduled before the retry count is down to 0
* [FEATURE] Added status STATUS_CANCELED and possibility to cancel job via cancelJob(ObjectId $id) (If not yet started)
* [FEATURE] Added more unit tests
