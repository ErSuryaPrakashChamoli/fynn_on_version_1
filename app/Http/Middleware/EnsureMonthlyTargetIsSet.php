<?php

namespace App\Http\Middleware;

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\MonthlyCommitmentTargets\MonthlyCommitmentTargetResource;
use App\Models\User;
use App\Services\MonthlyTargetGate;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Closes the panel while the calendar month's commitment targets are
 * still unfixed.
 *
 * The prompt itself is a modal (App\Livewire\MonthlyTargetPrompt, hung on
 * the panel's body render hook), so a blocked user always sees why. This
 * middleware is the server-side half: typing a URL, following a stale
 * link or hitting a bookmarked page must not walk around the modal.
 *
 * A user who can fix targets is sent to the Monthly Target screen — the
 * one place they can clear the block. Everyone else waits on their
 * Manager or the Admin line, so they land on the dashboard with the
 * modal over it and nothing else reachable.
 */
class EnsureMonthlyTargetIsSet
{
    public function __construct(private MonthlyTargetGate $gate) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = Filament::auth()->user();

        if (! $user || ! $this->gate->isBlocked($user)) {
            return $next($request);
        }

        if ($this->isPermitted($request, $user)) {
            return $next($request);
        }

        return redirect()->to($this->landingUrl($user));
    }

    /**
     * Signing out, changing a password and the target screen itself stay
     * open — everything else in the panel is closed.
     */
    private function isPermitted(Request $request, User $user): bool
    {
        $name = $request->route()?->getName() ?? '';

        if (str_contains($name, '.auth.')) {
            return true;
        }

        if (str_contains($name, '.pages.change-password')) {
            return true;
        }

        if ($this->gate->isTargetSetter($user)) {
            return str_contains($name, '.resources.monthly-commitment-targets.');
        }

        return str_contains($name, '.pages.dashboard');
    }

    private function landingUrl(User $user): string
    {
        return $this->gate->isTargetSetter($user)
            ? MonthlyCommitmentTargetResource::getUrl()
            : Dashboard::getUrl();
    }
}
