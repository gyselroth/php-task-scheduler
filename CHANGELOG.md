## 2.0.1
**Maintainer**: Raffael Sahli <sahli@gyselroth.com>\
**Date**: 

* [CHANGE] queue collection gets now automatically created, even if the queue gets removed during processing
* [FEATURE] there is no need anymore to create the queue collection, it is handled automatically by TaskScheduler\Queue
* [FEATURE] retry can now be set to 0 and the job gets rescheduled if failed endlessly according to interval. -1 (Default is reserved for no retry)

## 2.0.0
**Maintainer**: Raffael Sahli <sahli@gyselroth.com>\
**Date**: Wed Mar 14 15:55:22 CET 2018

* [!Breaker] Split Async::class into Queue::class and Scheduler::class
* [FEATURE] Abondend timestamp field and added created, started and ended timestamps

## 1.0.2
**Maintainer**: Raffael Sahli <sahli@gyselroth.com>\
**Date**: Thu Feb 15 08:34:01 CET 2018

* [FIX] fixed typeMap from BSONDocument to array for all getter
* [FIX] fixed "Uncaught MongoDB\Driver\Exception\RuntimeException: The cursor is invalid or has expired" in certain cases
* [FIX] Job with interval gets not rescheduled before the retry count is down to 0
* [FEATURE] Added status STATUS_CANCELED and possibility to cancel job via cancelJob(ObjectId $id) (If not yet started)
* [FEATURE] Added more unit tests
