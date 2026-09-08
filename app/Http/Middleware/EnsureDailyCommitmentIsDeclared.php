<?php

namespace App\Http\Middleware;

use App\Filament\Pages\MyDailyCommitment;
use App\Services\DailyCommitmentGate;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Holds the panel shut while a daily commitment is outstanding.
 *
 * Past 09:50 with no commitment for today, or past 18:30 with the day
 * still unanswered, the only screen that stays reachable is My Commitment
 * — the one place the block can be cleared. The prompt component
 * (App\Livewire\DailyCommitmentPrompt) is the visible half; this is the
 * server-side one, so a typed URL or a bookmark cannot walk around it.
 *
 * Only the Daily Commitment module decides this. No other module's routes
 * or behaviour are changed — they simply become unreachable for as long
 * as the employee owes a commitment or a declaration.
 */
class EnsureDailyCommitmentIsDeclared
{
    public function __construct(private DailyCommitmentGate $gate) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = Filament::auth()->user();

        if (! $user || ! $this->gate->isBlocked($user)) {
            return $next($request);
        }

        if ($this->isPermitted($request)) {
            return $next($request);
        }

        return redirect()->to(MyDailyCommitment::getUrl());
    }

    /**
     * Signing out, changing a password and the commitment screen itself
     * stay open — everything else in the panel is closed.
     */
    private function isPermitted(Request $request): bool
    {
        $name = $request->route()?->getName() ?? '';

        return str_contains($name, '.auth.')
            || str_contains($name, '.pages.change-password')
            || str_contains($name, '.pages.my-daily-commitment');
    }
}
