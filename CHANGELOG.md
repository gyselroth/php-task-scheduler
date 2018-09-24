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
