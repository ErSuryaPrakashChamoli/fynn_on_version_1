<?php

namespace App\Http\Controllers;

use App\Models\Demo\DemoUser;
use App\Support\Demo\DemoLoginSwitcher;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The /demo "View as" switcher.
 *
 * Two ways in, both from the topbar menu:
 *  - POST /demo/switch-role/{role}  — the seeded login for a role
 *    (config('demo.role_logins'), e.g. "Back to Admin");
 *  - POST /demo/switch-user/{user}  — any specific active demo user, so a
 *    demonstration can show what one particular caller, team leader or
 *    manager sees and may change.
 *
 * Everything the switched-to user then does goes through the normal role
 * and hierarchy checks of the shared admin code. Demo-only by
 * construction: the routes live in the /demo group (demo context,
 * `auth:demo`) and the target is always a DemoUser in the demo database.
 */
class SwitchDemoRoleController extends Controller
{
    public function __construct(protected DemoLoginSwitcher $switcher) {}

    public function __invoke(Request $request, string $role): RedirectResponse
    {
        $login = config("demo.role_logins.{$role}");

        abort_if($login === null, 404);

        $target = DemoUser::query()->where('email', $login['email'])->first();

        if ($target === null || $target->isDeactivated()) {
            return (new RedirectResponse(url()->previous()))
                ->setSession($request->session())
                ->with('demo_role_switch_error', "The {$login['label']} demo login is not available. Rebuild the demo with `php artisan demo:reset`.");
        }

        return $this->switchTo($request, $target);
    }

    public function user(Request $request, int $user): RedirectResponse
    {
        $target = DemoUser::query()->find($user);

        abort_if($target === null || $target->isDeactivated(), 404);

        return $this->switchTo($request, $target);
    }

    protected function switchTo(Request $request, DemoUser $target): RedirectResponse
    {
        $this->switcher->switchTo($request, $target);

        // A plain RedirectResponse rather than redirect(): after a Livewire
        // request the `redirect` service is Livewire's own Redirector.
        return new RedirectResponse(Filament::getPanel('demo')->getUrl());
    }
}
