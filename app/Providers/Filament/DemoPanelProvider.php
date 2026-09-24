<?php

namespace App\Providers\Filament;

use App\Http\Middleware\EncryptCookies;
use App\Http\Middleware\EnforceIdleTimeout;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureMonthlyTargetIsSet;
use App\Http\Middleware\Portal\EnsureDemoAccess;
use App\Http\Middleware\Portal\EnsureDemoIsAvailable;
use App\Http\Middleware\Portal\UseDemoContext;
use App\Support\Demo\DemoContext;
use App\Support\Demo\DemoDatabaseQueue;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Queue\Connectors\DatabaseConnector;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\QueueManager;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Event;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use RuntimeException;

/**
 * /demo — the SAME admin application, running against the demo
 * environment.
 *
 * Extends AdminPanelProvider and builds the panel from the same
 * configureSharedPanel(): every resource, page, widget, relation manager,
 * render hook, theme, navigation group and user-menu item of /admin is
 * here too, and anything added to /admin later appears here without a
 * second implementation. The only differences are:
 *
 *  - identity: id/path `demo`, the `demo` auth guard (DemoUser rows in the
 *    demo database) — a main login is a guest here and vice versa;
 *  - context: EnsureDemoIsAvailable + UseDemoContext run first on every
 *    request and Livewire round-trip, switching database, queue, cache,
 *    storage and mail to the demo side (see DemoContext);
 *  - a persistent "Demo environment" badge and a one-click "View as"
 *    role switcher (SwitchDemoRoleController).
 */
class DemoPanelProvider extends AdminPanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $this->configureSharedPanel(
            $panel
                ->id('demo')
                ->path('demo')
        )
            ->authGuard('demo')
            /*
             * The demo marker and one-click "View as" switcher float in the
             * bottom-right corner, so the admin layout (topbar marquee,
             * sidebar) stays exactly as on /admin. A thin amber line along
             * the topbar keeps "this is the demo" visible at a glance.
             */
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => Filament::auth()->check()
                    ? view('filament.demo.role-switcher')->render()
                    : '',
            )
            ->renderHook(
                PanelsRenderHook::STYLES_AFTER,
                fn (): string => '<style>.fi-topbar{box-shadow:inset 0 3px 0 rgb(245 158 11)}</style>',
            )
            ->renderHook(
                PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE,
                fn (): string => view('filament.demo.environment-badge')->render(),
            )
            /*
             * The globally-registered login-session heartbeat posts to the
             * main /login-session/heartbeat route by default; on /demo it
             * must hit the demo copy, which runs in the demo context.
             */
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => '<meta name="login-session-heartbeat-url" content="'
                    .e(route('demo.login-session.heartbeat', absolute: false)).'">',
            )
            ->middleware([
                EnsureDemoIsAvailable::class,
                UseDemoContext::class,
            ], isPersistent: true)
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                EnsureAccountIsActive::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                EnsureDemoAccess::class,
            ], isPersistent: true)
            ->authMiddleware([
                Authenticate::class,
                EnforceIdleTimeout::class,
                EnsureMonthlyTargetIsSet::class,
            ]);
    }

    /**
     * The panel-wide registrations in AdminPanelProvider::boot() are
     * global (they apply to every panel) and must run once, so they are
     * not repeated here.
     *
     * What IS registered here is the queue boundary: a job pushed from
     * the demo environment sits on the `demo` queue connection (the demo
     * database), and may only ever be processed inside the demo context —
     * i.e. by `php artisan demo:queue-work`. DemoDatabaseQueue refuses to
     * hand out a job anywhere else (a plain `queue:work demo` would run it
     * against the MAIN database), and a demo worker refuses anything that
     * is not a demo job.
     */
    public function boot(): void
    {
        $this->callAfterResolving('queue', function (QueueManager $manager): void {
            $manager->addConnector('demo-database', fn (): DatabaseConnector => new class(app('db')) extends DatabaseConnector
            {
                public function connect(array $config): DemoDatabaseQueue
                {
                    return new DemoDatabaseQueue(
                        $this->connections->connection($config['connection']),
                        $config['table'],
                        $config['queue'],
                        $config['retry_after'] ?? 60,
                        $config['after_commit'] ?? null,
                    );
                }
            });
        });

        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            $isDemoJob = $event->connectionName === 'demo';

            if ($isDemoJob && ! DemoContext::isActive()) {
                throw new RuntimeException('Demo queue jobs must be processed by `php artisan demo:queue-work`, never by a main-database worker.');
            }

            if (! $isDemoJob && DemoContext::isActive() && $event->connectionName !== 'sync') {
                throw new RuntimeException("A demo worker must not process jobs from the [{$event->connectionName}] queue connection.");
            }
        });
    }
}
