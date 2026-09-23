<?php

namespace App\Http\Controllers;

use App\Support\DemoPersonas;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Signs the presenter in as one of the seeded demo personas, from the
 * login page or from the topbar switcher. The route only exists while
 * DEMO_MODE is on (see DemoModeServiceProvider), and it can only reach
 * the addresses listed in config/demo.php — never an arbitrary user.
 *
 * It goes through a real logout and login, so the Login/Logout
 * listeners record the switch in the user login sessions exactly as
 * they would a typed-in sign-in.
 */
class DemoPersonaSwitchController extends Controller
{
    public function __invoke(Request $request, string $persona): RedirectResponse
    {
        abort_unless(DemoPersonas::enabled(), 404);

        $user = DemoPersonas::userFor($persona);

        abort_if($user === null, 404);

        $guard = Auth::guard(Filament::getPanel('admin')->getAuthGuard());

        if ($guard->check()) {
            $guard->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $guard->login($user);
        $request->session()->regenerate();

        return redirect()->to(Filament::getPanel('admin')->getUrl());
    }
}
