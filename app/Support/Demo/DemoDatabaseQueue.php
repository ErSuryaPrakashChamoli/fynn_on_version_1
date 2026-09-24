<?php

namespace App\Support\Demo;

use Illuminate\Queue\DatabaseQueue;
use RuntimeException;

/**
 * The `demo` queue connection: the jobs table of the DEMO database.
 *
 * A demo job has to run inside the demo context — otherwise the unchanged
 * job code would read and write the MAIN database. pop() therefore
 * refuses outside that context, before a job is reserved: a plain
 * `queue:work demo` stops with an error instead of running (or failing,
 * and recording into the main failed_jobs table) a single demo job. The
 * supported worker is `php artisan demo:queue-work`.
 *
 * Pushing and size() (queue:monitor) are unaffected.
 */
class DemoDatabaseQueue extends DatabaseQueue
{
    public function pop($queue = null)
    {
        if (! DemoContext::isActive()) {
            throw new RuntimeException('The demo queue may only be worked inside the demo context. Use `php artisan demo:queue-work`.');
        }

        return parent::pop($queue);
    }
}
