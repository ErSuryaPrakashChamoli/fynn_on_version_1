<?php

namespace App\Support\Demo;

use App\Models\Demo\DemoUser;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * The /demo "View as" switcher's two halves: which demo logins can be
 * switched to (grouped by role, for the topbar menu) and the switch
 * itself.
 *
 * Only ever works with DemoUser — rows in the DEMO database — so it
 * cannot sign anyone in to /admin or reach a main-database user.
 */
class DemoLoginSwitcher
{
    /**
     * Every active demo login, grouped under the roles configured in
     * config('demo.role_logins'), in that order. A user holding several
     * roles is listed under the first one.
     *
     * @return Collection<string, Collection<int, DemoUser>> role label => users
     */
    public function usersByRole(): Collection
    {
        $roleOrder = collect(config('demo.role_logins', []))->pluck('label')->values();

        $users = DemoUser::query()
            ->with(['roles:id,name', 'employee:id,emp_id,emp_name,exit_status'])
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->reject(fn (DemoUser $user): bool => $user->isDeactivated());

        return $roleOrder
            ->mapWithKeys(fn (string $role): array => [
                $role => $users->filter(fn (DemoUser $user): bool => $user->roles->pluck('name')->first(
                    fn (string $name): bool => $roleOrder->contains($name),
                ) === $role)->values(),
            ])
            ->filter(fn (Collection $group): bool => $group->isNotEmpty());
    }

    /**
     * Sign the current browser in to /demo as the given demo login.
     */
    public function switchTo(Request $request, DemoUser $target): void
    {
        Auth::guard('demo')->login($target);

        $request->session()->regenerate();

        // Filament's AuthenticateSession pins the session to the signed-in
        // user's password hash; re-pin it to the new login.
        $request->session()->put('password_hash_demo', $target->getAuthPassword());
    }
}
