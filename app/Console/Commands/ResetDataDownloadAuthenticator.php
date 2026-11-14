<?php

namespace App\Console\Commands;

use App\Services\DataDownloadAuthenticatorService;
use Illuminate\Console\Command;

/**
 * For when the owner's phone is lost: removes the owner authenticator so
 * it can be set up again on the Data Download page. Needs server access on
 * purpose, since replacing it from the app needs a code from the old phone.
 * Downloads stay blocked until the owner sets a new one up.
 */
class ResetDataDownloadAuthenticator extends Command
{
    protected $signature = 'data-download:reset-authenticator {--force : Skip the confirmation prompt}';

    protected $description = 'Remove the owner authenticator that approves leads / customers data downloads (e.g. the owner\'s phone was lost)';

    public function handle(DataDownloadAuthenticatorService $service): int
    {
        $authenticator = $service->current();

        if ($authenticator === null) {
            $this->info('No owner authenticator is set up.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Remove {$authenticator->owner_name}'s authenticator? Data downloads stay blocked until the owner sets up a new one.")) {
            $this->warn('Nothing changed.');

            return self::FAILURE;
        }

        $service->remove();

        $this->info('Owner authenticator removed. The owner can set up a new one on the Data Download page.');

        return self::SUCCESS;
    }
}
