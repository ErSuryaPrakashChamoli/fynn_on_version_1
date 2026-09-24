<?php

namespace App\Console\Commands;

use App\Support\Demo\DemoContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/**
 * Runs an existing artisan command against the DEMO environment.
 *
 *     php artisan demo:run journey:check-sla-breaches
 *     php artisan demo:run "daily-commitment:settle"
 *
 * The command runs inside the demo context (demo database, cache, queue,
 * storage), so the same code that maintains the main data maintains the
 * demo data — this is how the scheduled jobs get their demo counterparts
 * in routes/console.php. Schema and seeding commands are refused: those
 * go through `demo:migrate`, which applies its own safety checks.
 */
class DemoRunCommand extends Command
{
    protected $signature = 'demo:run
        {line* : The artisan command (and its arguments) to run in the demo environment}';

    protected $description = 'Run an artisan command inside the demo environment (DEMO database only)';

    /**
     * @var list<string>
     */
    protected array $refused = ['migrate', 'db:', 'schema:', 'demo:', 'queue:work', 'queue:listen'];

    public function handle(): int
    {
        $line = implode(' ', $this->argument('line'));
        $command = Str::before($line, ' ');

        if (Str::startsWith($command, $this->refused)) {
            $this->error("[{$command}] cannot be run through demo:run. Use demo:migrate / demo:queue-work instead.");

            return self::FAILURE;
        }

        return DemoContext::run(function () use ($line): int {
            $this->components->info("Running [{$line}] against the DEMO database [".config('database.connections.demo.database').'].');

            return Artisan::call($line, [], $this->output);
        });
    }
}
