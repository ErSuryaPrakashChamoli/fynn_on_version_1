<?php

namespace App\Console\Commands;

use App\Support\Demo\DemoContext;
use Illuminate\Console\Command;

/**
 * The /demo queue worker.
 *
 *     php artisan demo:queue-work --queue=default
 *     php artisan demo:queue-work --queue=quick
 *
 * Switches the whole worker process into the demo context first, then
 * works the `demo` queue connection (the jobs table of the DEMO
 * database). Every job it runs — imports, exports, OCR, queued
 * notifications — therefore reads and writes the demo database, cache and
 * storage only. A plain `queue:work demo` is refused per job by the guard
 * in DemoPanelProvider::boot(), because it would run demo jobs against
 * the main database.
 *
 * See deploy/supervisor/fynn-demo-worker.conf for the production program.
 */
class DemoQueueWork extends Command
{
    protected $signature = 'demo:queue-work
        {--queue=default : The demo queues to work (e.g. "quick" or "default")}
        {--sleep=3 : Seconds to sleep when no job is available}
        {--tries=2 : Attempts before a job is marked failed}
        {--timeout=3600 : Seconds a single job may run}
        {--max-time=0 : Seconds the worker should run before restarting}
        {--memory=128 : Memory limit in megabytes before the worker restarts}
        {--stop-when-empty : Stop once the demo queue is empty}';

    protected $description = 'Work the DEMO queue inside the demo context (demo database, cache and storage)';

    public function handle(): int
    {
        DemoContext::activate();

        $this->components->info('Working the DEMO queue on connection [demo] (demo database ['
            .config('database.connections.demo.database').']).');

        return $this->call('queue:work', [
            'connection' => 'demo',
            '--queue' => $this->option('queue'),
            '--sleep' => $this->option('sleep'),
            '--tries' => $this->option('tries'),
            '--timeout' => $this->option('timeout'),
            '--max-time' => $this->option('max-time'),
            '--memory' => $this->option('memory'),
            '--stop-when-empty' => (bool) $this->option('stop-when-empty'),
        ]);
    }
}
