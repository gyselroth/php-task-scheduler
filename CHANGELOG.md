## 2.0.0
**Maintainer**: Raffael Sahli <sahli@gyselroth.com>\
**Date**: Fri Mar 09 15:55:22 CET 2018

* [!Breaker] Split Async::class into Queue::class and Scheduler::class


## 1.0.2
**Maintainer**: Raffael Sahli <sahli@gyselroth.com>\
**Date**: Thu Feb 15 08:34:01 CET 2018

* [FIX] fixed typeMap from BSONDocument to array for all getter
* [FIX] fixed "Uncaught MongoDB\Driver\Exception\RuntimeException: The cursor is invalid or has expired" in certain cases
* [FIX] Job with interval gets not rescheduled before the retry count is down to 0
* [FEATURE] Added status STATUS_CANCELED and possibility to cancel job via cancelJob(ObjectId $id) (If not yet started)
* [FEATURE] Added more unit tests
