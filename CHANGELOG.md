## 3.0.0
**Maintainer**: Raffael Sahli <sahli@gyselroth.com>\
**Date**: 

* [FIX] Added pnctl requirement to composer.json
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
