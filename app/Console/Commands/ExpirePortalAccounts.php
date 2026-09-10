<?php

namespace App\Console\Commands;

use App\Models\PortalAccount;
use App\Services\Portal\PortalAccountService;
use Illuminate\Console\Command;

/**
 * Deactivates demo and training accounts whose expiry has passed.
 *
 *     php artisan portal:expire-accounts
 *
 * Scheduled hourly (see routes/console.php). An expired account is
 * already refused at request time by PortalAccount::isUsable(), so this
 * is housekeeping rather than the enforcement itself — it makes the
 * stored state match reality so the admin listing tells the truth.
 */
class ExpirePortalAccounts extends Command
{
    protected $signature = 'portal:expire-accounts {--report : List upcoming expiries without changing anything}';

    protected $description = 'Deactivate portal accounts past their expiry date';

    public function handle(PortalAccountService $accounts): int
    {
        if ($this->option('report')) {
            return $this->report();
        }

        $expired = $accounts->deactivateExpired();

        $this->info($expired === 0
            ? 'No portal accounts needed expiring.'
            : "Deactivated {$expired} expired portal account(s).");

        return self::SUCCESS;
    }

    protected function report(): int
    {
        $upcoming = PortalAccount::query()
            ->with('user')
            ->where('is_active', true)
            ->whereNotNull('expires_at')
            ->orderBy('expires_at')
            ->get();

        if ($upcoming->isEmpty()) {
            $this->comment('No portal accounts have an expiry date set.');

            return self::SUCCESS;
        }

        $this->table(
            ['User', 'Portal', 'Role', 'Expires', 'Status'],
            $upcoming->map(fn (PortalAccount $account): array => [
                $account->user?->email ?? '—',
                $account->portal->value,
                $account->portal_role->value,
                $account->expires_at?->format('d M Y H:i'),
                $account->hasExpired() ? 'EXPIRED' : 'active',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
