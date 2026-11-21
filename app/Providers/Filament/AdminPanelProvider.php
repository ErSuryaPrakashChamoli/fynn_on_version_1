<?php

namespace App\Providers\Filament;

use App\Filament\Pages\AssignedLeadFollowUpCalendar;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\CashbackAnalysis;
use App\Filament\Pages\ChangePassword;
use App\Filament\Pages\CibilAccessSettings;
use App\Filament\Pages\CibilAuditHistory;
use App\Filament\Pages\CibilDashboard;
use App\Filament\Pages\CibilEmployeeActivity;
use App\Filament\Pages\CibilSettings;
use App\Filament\Pages\CustomerFollowUpCalendar;
use App\Filament\Pages\CustomerFollowUpOutcomes;
use App\Filament\Pages\DailyCommitmentDashboard;
use App\Filament\Pages\DailyCommitmentReports;
use App\Filament\Pages\DailyCommitmentTeamView;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\DashboardGreetingSettings;
use App\Filament\Pages\DataDownload;
use App\Filament\Pages\EmployeeHierarchy;
use App\Filament\Pages\EmployeeLookup;
use App\Filament\Pages\EmployeePerformanceDashboard;
use App\Filament\Pages\FollowUpMonitor;
use App\Filament\Pages\JourneyContinuityDashboard;
use App\Filament\Pages\LeadFollowUpOutcomes;
use App\Filament\Pages\LoginPageSettings;
use App\Filament\Pages\MyDailyCommitment;
use App\Filament\Pages\MyProfile;
use App\Filament\Pages\MyVotes;
use App\Filament\Pages\OtherBankSupportDashboard;
use App\Filament\Pages\TeamPerformance;
use App\Filament\Resources\AccountVerifications\AccountVerificationResource;
use App\Filament\Resources\ActivityLogs\ActivityLogResource;
use App\Filament\Resources\AiCustomerRecords\AiCustomerRecordResource;
use App\Filament\Resources\AiDocumentSchemas\AiDocumentSchemaResource;
use App\Filament\Resources\Announcements\AnnouncementResource;
use App\Filament\Resources\AssignedLeads\AssignedLeadResource;
use App\Filament\Resources\CibilReports\CibilReportResource;
use App\Filament\Resources\CibilRequests\CibilRequestResource;
use App\Filament\Resources\Cities\CityResource;
use App\Filament\Resources\ComplaintCategories\ComplaintCategoryResource;
use App\Filament\Resources\ComplaintPriorities\ComplaintPriorityResource;
use App\Filament\Resources\Complaints\ComplaintResource;
use App\Filament\Resources\CostCenters\CostCenterResource;
use App\Filament\Resources\CustomerCashbackRequests\CustomerCashbackRequestResource;
use App\Filament\Resources\CustomerEditRequests\CustomerEditRequestResource;
use App\Filament\Resources\CustomerEligibilityRequests\CustomerEligibilityRequestResource;
use App\Filament\Resources\CustomerJourneyAudits\CustomerJourneyAuditResource;
use App\Filament\Resources\CustomerJourneyDelegations\CustomerJourneyDelegationResource;
use App\Filament\Resources\CustomerPanRequests\CustomerPanRequestResource;
use App\Filament\Resources\CustomerReassignments\CustomerReassignmentResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\CustomerSettlements\CustomerSettlementResource;
use App\Filament\Resources\CustomerSlaBreaches\CustomerSlaBreachResource;
use App\Filament\Resources\Designations\DesignationResource;
use App\Filament\Resources\EmployeePerformanceReports\EmployeePerformanceReportResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\FollowUps\FollowUpResource;
use App\Filament\Resources\JourneyTakeovers\JourneyTakeoverResource;
use App\Filament\Resources\LeadAssignmentReports\LeadAssignmentReportResource;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\MonthlyCommitmentTargets\MonthlyCommitmentTargetResource;
use App\Filament\Resources\OcrDocuments\OcrDocumentResource;
use App\Filament\Resources\OtherBankIncentiveSlabs\OtherBankIncentiveSlabResource;
use App\Filament\Resources\OtherBankSupportTargets\OtherBankSupportTargetResource;
use App\Filament\Resources\PendingManagerCases\PendingManagerCaseResource;
use App\Filament\Resources\PerformanceMetricRatios\PerformanceMetricRatioResource;
use App\Filament\Resources\Polls\PollResource;
use App\Filament\Resources\PollTypes\PollTypeResource;
use App\Filament\Resources\Positions\PositionResource;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\TargetCategories\TargetCategoryResource;
use App\Filament\Resources\Teams\TeamResource;
use App\Filament\Resources\Units\UnitResource;
use App\Filament\Resources\UserLoginSessions\UserLoginSessionResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Widgets\CustomerStats;
use App\Filament\Widgets\DailyCommitmentStats;
use App\Filament\Widgets\IncentiveStats;
use App\Filament\Widgets\ManagerPPPStats;
use App\Filament\Widgets\PerformanceStats;
use App\Filament\Widgets\TargetStats;
use App\Http\Middleware\EncryptCookies;
use App\Http\Middleware\EnforceIdleTimeout;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureMonthlyTargetIsSet;
use App\Http\Middleware\RestrictItRoleToAllowedModules;
use App\Livewire\CategorizedDatabaseNotifications;
use App\Models\User;
use App\Models\UserLoginSession;
use App\Support\ColumnHeaderFilters\ColumnHeaderFilters;
use App\Support\ItModuleAccess;
use App\Support\SidebarShortcuts;
use App\Support\TablePaginationOptions;
use Filament\Actions\Action;
use Filament\Enums\ThemeMode;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
// use App\Filament\Widgets\AchievementChart;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Assets\Css;
use Filament\Support\Assets\Js;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\Support\Facades\FilamentAsset;
use Filament\Support\Facades\FilamentView;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Column;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Filament\Tables\View\TablesRenderHook;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Livewire\Livewire;
use Saade\FilamentFullCalendar\FilamentFullCalendarPlugin;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $this->configureSharedPanel(
            $panel
                ->default()
                ->id('admin')
                ->path('admin')
        )
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                // Sits here, not in authMiddleware(), because Laravel's
                // middleware priority hoists Filament's Authenticate above
                // anything registered there — and Authenticate answers a
                // switched-off account with a bare 403. Running first, this
                // turns that into a clean sign-out with a reason. Harmless
                // on the login page: it only ever acts on a signed-in user.
                EnsureAccountIsActive::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                // Ordered before the module gates on purpose: a user who
                // has gone idle should be signed out, not redirected to
                // whichever screen a gate wants them on.
                EnforceIdleTimeout::class,
                // An IT login may only reach the Reporting Hierarchy and
                // the Help Desk; every other URL lands on the hierarchy.
                RestrictItRoleToAllowedModules::class,
                EnsureMonthlyTargetIsSet::class,
            ]);
    }

    /**
     * Everything the admin UI is made of — theme, branding, navigation,
     * render hooks, resources, pages, widgets, user menu — minus the
     * panel's identity (id/path/guard) and its middleware.
     *
     * Shared with DemoPanelProvider, which is what makes /demo the same
     * application as /admin rather than a second implementation of it:
     * anything added here (or any resource/page/widget added under
     * app/Filament) appears on both panels.
     */
    protected function configureSharedPanel(Panel $panel): Panel
    {
        return $panel
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->spa()
            // The profile page's FileUpload (avatar) lazily initializes its
            // Alpine component via x-load and fetches its existing-file
            // preview over a Livewire round-trip; under a wire:navigate SPA
            // transition that races the component's hydration and the
            // preview gets stuck showing its loading state forever. A full
            // page load avoids the race, so this one route is excluded from
            // SPA navigation (everything else keeps ->spa() as before).
            // A wildcard pattern is used (rather than MyProfile::getUrl(),
            // which bakes in APP_URL's host:port) so this still matches
            // when the app is reached through a different port, e.g. a
            // second `php artisan serve` instance on 8001.
            ->spaUrlExceptions([
                '*/'.$panel->getPath().'/profile',
            ])
            ->login(Login::class)
            ->profile(MyProfile::class, isSimple: false)
            ->brandLogo(asset('images/fynnedge_image_aug.png'))
            ->brandLogoHeight('2.75rem')
            ->favicon(asset('images/favicon.png'))
            // The theme switcher (see its view override) drops the
            // "system" option, so a first-time visitor with no stored
            // preference yet needs a default that one of the two
            // remaining buttons can actually show as active.
            ->defaultThemeMode(ThemeMode::Light)
            // The sidebar rests as an icon rail and expands in place (the
            // content area shrinks to make room) while the cursor is on it — see
            // resources/js/sidebar-hover-expand.js, registered in boot().
            // The topbar's own expand button pins it open; collapse unpins.
            // Every group carries a module icon (buildNavigation()) and every
            // item its own submodule icon, which the published
            // filament-panels::components.sidebar.group view lets coexist.
            // The rail width is referenced by theme.css as
            // var(--collapsed-sidebar-width).
            ->sidebarCollapsibleOnDesktop()
            ->collapsedSidebarWidth('5rem')
            ->globalSearch(false)
            ->brandName('Finn On')
            // Filament falls back to a 7xl (1280px) content cap on every
            // page when nothing overrides it, which is what was leaving
            // large blank margins beside tables/cards on wide screens.
            // Setting it here removes that cap panel-wide instead of
            // per-page, so it never has to be repeated per resource.
            ->maxContentWidth(Width::Full)
            ->colors(fn (): array => $this->buildColors())
            /*
             * Large OCR documents can take a long time to process even
             * fully optimized — this is what lets a background job notify
             * the uploader (via the bell icon) once it's actually done,
             * instead of them having to keep the page open watching a
             * status badge. See OcrDocumentProcessor::process() and
             * ProcessOcrDocument::failed(). The bell is split into tabs by
             * NotificationCategory (CategorizedDatabaseNotifications).
             */
            ->databaseNotifications(livewireComponent: CategorizedDatabaseNotifications::class)
            ->databaseNotificationsPolling('30s')
            ->plugin(
                FilamentFullCalendarPlugin::make()
                    ->selectable()
                    ->editable(false)
            )
            // The top-performer marquee: its own full-width strip directly
            // under the topbar (user decision 2026-09-27), not a slot inside
            // it competing with the period selector and the bell. TOPBAR_AFTER
            // lands between the topbar and .fi-layout as a row of the app
            // shell's flex column (see GLOBAL APP SHELL in theme.css); the
            // sidebar is pushed down by the strip's height via :has() there.
            ->renderHook(
                PanelsRenderHook::TOPBAR_AFTER,
                // The performance marquee is a module IT does not get.
                fn (): string => ItModuleAccess::restricts(Filament::auth()->user())
                    ? ''
                    : '<div class="fynn-marquee-strip">'.Blade::render('@livewire("top-performer-marquee")').'</div>',
            )
            // The global month selector (see App\Support\SelectedMonth):
            // one topbar control that scopes every resource table and
            // dashboard widget panel-wide to a single selected calendar
            // month, persisted via a plain cookie the same way the theme
            // switcher persists dashboard_theme. GLOBAL_SEARCH_AFTER sits
            // inside .fi-topbar-end, right before the database
            // notifications bell — placing this here (rather than
            // TOPBAR_START, which sits ahead of the sidebar toggle and
            // logo) keeps the logo in its original spot and puts the
            // selector immediately to the bell's left.
            ->renderHook(
                PanelsRenderHook::GLOBAL_SEARCH_AFTER,
                fn (): string => view('filament.components.global-month-selector')->render(),
            )
            // Module search (Ctrl/⌘+K): a pop-up of every module and
            // sub-module in the user's own sidebar, filtered as they type.
            // Filament's record global search stays off (->globalSearch(false)).
            ->renderHook(
                PanelsRenderHook::GLOBAL_SEARCH_BEFORE,
                fn (): string => view('filament.components.module-search')->render(),
            )
            // "Find employee" (Ctrl/⌘+E): type a name or employee ID, pick the person
            // and their profile opens on the Employee Lookup page. The Admin
            // finds everyone; everybody else their own team down the line
            // (App\Support\EmployeeLookupAccess). Hidden from IT-restricted
            // logins along with the page itself.
            ->renderHook(
                PanelsRenderHook::GLOBAL_SEARCH_BEFORE,
                fn (): string => EmployeeLookup::canAccess()
                    ? Blade::render('@livewire("employee-quick-search")')
                    : '',
            )
            // Swapping ->colors() (see buildColors()) already recolors every
            // Filament component that reads the --primary-*/--gray-* CSS
            // variables it renders at request time. But the topbar/sidebar
            // gradients above are this app's own hardcoded indigo-navy
            // rgb() values (not variable-driven), so the Emerald + Charcoal
            // and FYNN-ON themes each need their own small override for
            // just those two surfaces. Emitted only when that theme is
            // active, so the default look is untouched.
            ->renderHook(
                PanelsRenderHook::STYLES_AFTER,
                fn (): string => $this->isEmeraldTheme() ? $this->emeraldChromeStyles() : '',
            )
            ->renderHook(
                PanelsRenderHook::STYLES_AFTER,
                fn (): string => $this->isFynnOnTheme() ? $this->fynnOnChromeStyles() : '',
            )
            // A small sticky arrow pinned to the bottom of the sidebar's own
            // scroll container (.fi-sidebar-nav), letting users jump back to
            // the top of a long nav list without hunting for the scrollbar.
            // A matching control used to sit above "Dashboard" at the top
            // (SIDEBAR_NAV_START) but was removed — it read as a stray
            // floating chevron rather than a nav control.
            ->renderHook(
                PanelsRenderHook::SIDEBAR_NAV_END,
                fn (): string => view('filament.components.sidebar-nav-scroll-up')->render(),
            )
            // Listens for the ★ on each sidebar submodule (sidebar/item.blade.php)
            // and saves it; SidebarShortcuts lists the starred ones under
            // Favourites. Hung on the body, outside the sidebar, so the
            // sidebar's own re-render never tears the listener down.
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => Filament::auth()->check()
                    ? Blade::render('@livewire("sidebar-favourites")')
                    : '',
            )
            // A time-of-day greeting ("Good Morning, <name>! Let's make
            // every move count!") just above the "Dashboard" heading, for
            // every user. Scoped to the Dashboard page only so it doesn't
            // leak onto every other page in the panel.
            ->renderHook(
                PanelsRenderHook::PAGE_START,
                fn (): string => view('filament.components.dashboard-greeting')->render(),
                scopes: Dashboard::class,
            )
            // Duplicates the table's "records per page" select at the top
            // of every table's toolbar (left side), so it doesn't only
            // exist below a full page of rows. Bound to the same
            // tableRecordsPerPage property the bottom selector uses.
            ->renderHook(
                TablesRenderHook::TOOLBAR_START,
                fn (): string => view('filament.components.table-records-per-page-top')->render(),
            )
            // The Follow-up Outcomes pages' On time / Late / Missed / Open
            // tabs, centred in the table toolbar between that per-page
            // select and the search (theme.css). Scoped hooks render after
            // the unscoped one, so the per-page select stays first.
            ->renderHook(
                TablesRenderHook::TOOLBAR_START,
                fn (): string => view('filament.pages.follow-up-outcome-tabs', ['livewire' => Livewire::current()])->render(),
                scopes: [LeadFollowUpOutcomes::class, CustomerFollowUpOutcomes::class],
            )
            // A floating "scroll to top" arrow that hovers over every
            // resource List page's table while the user is scrolled down,
            // for jumping back up past a long page of rows without hunting
            // for the scrollbar. Takes no space below the pagination bar.
            // Scoped to List pages specifically (not relation-manager
            // tables) via this hook.
            ->renderHook(
                PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER,
                fn (): string => view('filament.components.table-scroll-to-top')->render(),
            )
            // The Daily Commitment module's monthly target has to be fixed
            // by hand every calendar month, so from the 1st it blocks the
            // panel until it exists: managers fix their callers, the Admin
            // line fixes managers and team leaders, and everyone else is
            // told who to chase. Hung on the body so it covers every page;
            // EnsureMonthlyTargetIsSet below is the server-side half.
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => Filament::auth()->check()
                    ? Blade::render('@livewire("monthly-target-prompt")')
                    : '',
            )
            // Voting & Feedback: a MANDATORY poll the user has not voted on
            // blocks the panel until they do (optional polls only wait on
            // the My Votes page). Same non-dismissible pattern as the
            // announcement prompt; PollService::blockingFor() is the query.
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => Filament::auth()->check()
                    ? Blade::render('@livewire("poll-prompt")')
                    : '',
            )
            // The same module's daily half: a commitment is owed by 09:50
            // and its achievement declared by 18:30. Deliberately a modal
            // ONLY — it is answered in place and then dismissed. It must
            // never redirect or close routes, because the rest of the LMS
            // has to stay usable while it is open.
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => Filament::auth()->check()
                    ? Blade::render('@livewire("daily-commitment-prompt")')
                    : '',
            )
            // Pops each pending bell notification (follow-ups coming due,
            // eligibility, PAN requests, …) up in front of the user to be
            // closed with remarks, skipped or rescheduled. Not blocking.
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => Filament::auth()->check()
                    ? Blade::render('@livewire("reminder-popup")')
                    : '',
            )
            // Announcements sent from Setting → Announcements block the LMS
            // until each recipient acknowledges them (AnnouncementPrompt).
            // Registered last so it sits above the other body-end prompts.
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => Filament::auth()->check()
                    ? Blade::render('@livewire("announcement-prompt")')
                    : '',
            )
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
                ChangePassword::class,
            ])
            ->navigation($this->buildNavigation(...))
            ->userMenuItems([
                // Overriding the 'profile' key means fully restating Filament's
                // own default label/url for it — MenuItem::toAction() replaces
                // all of an item's fields wholesale (not just the ones this
                // sets), so leaving any of those out would silently blank the
                // link. ->sort(-1) also has to be restated: it's what keeps
                // this item rendered in the dropdown block *above* the theme
                // switcher (see the .fi-user-menu ordering rules in theme.css)
                // rather than falling in with change_password/logout below it.
                'profile' => MenuItem::make()
                    ->label(fn (): string => MyProfile::getLabel() ?? Filament::getUserName(Filament::auth()->user()))
                    ->icon(Heroicon::Identification)
                    ->color('info')
                    ->url(fn (): ?string => Filament::getProfileUrl())
                    ->sort(-1),
                'change_password' => MenuItem::make()
                    ->label('Change Password')
                    ->icon(Heroicon::LockClosed)
                    ->color('warning')
                    ->url(fn (): string => ChangePassword::getUrl())
                    ->visible(fn (): bool => ChangePassword::shouldRegisterNavigation() && ChangePassword::canAccess())
                    ->sort(1),
                // The POST-to-logout-URL mechanics are restated as-is from
                // Filament's own default (see the same toAction() caveat
                // above) — only the label, icon, and color are changed.
                'logout' => MenuItem::make()
                    ->label('Relax Out')
                    ->icon(Heroicon::FaceSmile)
                    ->color('success')
                    ->postAction(fn (): string => Filament::getLogoutUrl())
                    ->sort(PHP_INT_MAX),
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                // TargetStats::class,
                DailyCommitmentStats::class,
                IncentiveStats::class,
                PerformanceStats::class,
                ManagerPPPStats::class,
                CustomerStats::class,

            ]);
    }

    /**
     * The active theme's color palette. "FYNN-ON" (lime + charcoal, the
     * brand palette) is the panel's default; the user can opt into
     * "Indigo + Teal" (the original look) or "Emerald + Charcoal"
     * instead via the theme switcher (see the
     * `filament-panels::components.theme-switcher.index` view override),
     * recorded as a `dashboard_theme` cookie. Filament renders every
     * `--{name}-{shade}` CSS variable these feed from ->colors() fresh on
     * each request, so this alone recolors buttons, links, badges, focus
     * rings, table chrome, form inputs, and nav highlights panel-wide.
     * See emeraldChromeStyles()/fynnOnChromeStyles() for the couple of
     * surfaces that don't read those variables.
     *
     * @return array<string, array<int, string> | string>
     */
    protected function buildColors(): array
    {
        return match ($this->activeTheme()) {
            'classic' => [
                'primary' => Color::Indigo,
                'teal' => Color::Teal,
                'success' => Color::Emerald,
                'warning' => Color::Amber,
                'danger' => Color::Rose,
                'info' => Color::Sky,
                'gray' => Color::Slate,
            ],
            'emerald' => [
                'primary' => Color::Emerald,
                'teal' => Color::Teal,
                'success' => Color::Emerald,
                'warning' => Color::Amber,
                'danger' => Color::Rose,
                'info' => Color::Sky,
                'gray' => '#26262b',
            ],
            default => [
                // Anchored on the exact FYNN-ON brand hexes: primary-500
                // is the Primary Brand Green, 600/700 are its specified
                // hover/active-pressed shades, and 300 is the Bright Lime
                // Highlight. The remaining shades are interpolated to fit
                // Tailwind's usual 50-950 lightness curve around those.
                'primary' => [
                    50 => '#F5FBE0',
                    100 => '#EAF7C2',
                    200 => '#DCF299',
                    300 => '#C8E83C',
                    400 => '#B7DE1A',
                    500 => '#A6D900',
                    600 => '#8FBE00',
                    700 => '#7FAF00',
                    800 => '#5E8200',
                    900 => '#3F5700',
                    950 => '#223000',
                ],
                // The existing sidebar active-item accent glow (theme.css)
                // reads var(--teal-300) — pointing it at the brand's
                // Bright Lime Highlight recolors that glow to match
                // without needing a separate override.
                'teal' => Color::generatePalette('#C8E83C'),
                'success' => Color::generatePalette('#8FBE00'),
                'warning' => Color::Amber,
                'danger' => Color::Rose,
                'info' => Color::Sky,
                // Anchored on the exact FYNN-ON neutrals: gray-50 is the
                // Main Background, gray-200 the Border, gray-500 the
                // Secondary Text, and gray-800/900/950 the Secondary
                // Dark / Charcoal / Primary Dark chrome tones.
                'gray' => [
                    50 => '#F7F8F6',
                    100 => '#EEF0EC',
                    200 => '#DDE1D8',
                    300 => '#C6CBC1',
                    400 => '#9AA296',
                    500 => '#626862',
                    600 => '#4D524C',
                    700 => '#383C37',
                    800 => '#222222',
                    900 => '#1B1B1B',
                    950 => '#151515',
                ],
            ],
        };
    }

    /**
     * FYNN-ON is the panel's default look, active whenever the
     * `dashboard_theme` cookie isn't one of the other themes' own values
     * (see the theme switcher view override).
     *
     * @return 'classic'|'emerald'|'fynnon'
     */
    protected function activeTheme(): string
    {
        return match (request()->cookie('dashboard_theme')) {
            'classic' => 'classic',
            'emerald' => 'emerald',
            default => 'fynnon',
        };
    }

    protected function isEmeraldTheme(): bool
    {
        return $this->activeTheme() === 'emerald';
    }

    protected function isFynnOnTheme(): bool
    {
        return $this->activeTheme() === 'fynnon';
    }

    /**
     * Recolors the sidebar and topbar chrome to charcoal. These are
     * theme.css's own hardcoded rgb() gradients, not driven by the
     * --primary- / --gray- CSS variables buildColors() controls, so they
     * need their own override here. The content area between them is left
     * alone — the emerald theme forces light mode (see the theme-switcher
     * view override), so it already renders on light mode's white
     * background without any extra rule.
     */
    protected function emeraldChromeStyles(): string
    {
        return <<<'HTML'
            <style>
                .fi-main-sidebar {
                    background: linear-gradient(180deg, rgb(38 38 43), rgb(24 24 27) 55%, rgb(9 9 11)) !important;
                }

                .fi-topbar {
                    background: linear-gradient(180deg, rgb(38 38 43), rgb(38 38 43) 60%, rgb(24 24 27)) !important;
                }
            </style>
            HTML;
    }

    /**
     * Recolors the sidebar and topbar chrome to the brand's #151515
     * Primary Dark, same rationale as emeraldChromeStyles(). Also
     * overrides the active sidebar nav item's text/icon color: that's
     * hardcoded to white in theme.css because every other theme's active
     * item background is a mid-to-dark primary shade, but FYNN-ON's
     * primary-500 is a bright lime — white text on it would fail contrast,
     * and the brand spec calls for #151515 text on that lime background
     * specifically.
     */
    protected function fynnOnChromeStyles(): string
    {
        return <<<'HTML'
            <style>
                .fi-main-sidebar {
                    background: linear-gradient(180deg, rgb(21 21 21), rgb(21 21 21) 55%, rgb(9 9 9)) !important;
                }

                .fi-topbar {
                    background: linear-gradient(180deg, rgb(21 21 21), rgb(21 21 21) 60%, rgb(9 9 9)) !important;
                }

                .fi-main-sidebar .fi-sidebar-item.fi-active > .fi-sidebar-item-btn,
                .fi-main-sidebar .fi-sidebar-item.fi-active .fi-sidebar-item-icon {
                    color: rgb(21 21 21) !important;
                    text-shadow: none;
                }
            </style>
            HTML;
    }

    /**
     * The sidebar: Dashboard on top, then nine modules A to Z (user decisions
     * 2026-11-14 — sixteen thin modules were merged into nine so the list is
     * short and every submodule sits under the module it belongs to). Insert
     * a new module at its alphabetical position; SidebarModuleAccordionTest
     * guards the order. Submodules are put A to Z inside each module, and
     * the Favourites (starred submodules, below Dashboard) and Recent (last
     * opened, at the bottom) sections are added, by SidebarShortcuts::arrange().
     *
     * Merging modules never widens access: navigationItemsFor() runs each
     * submodule's own shouldRegisterNavigation()/canAccess() (and the IT
     * filter), so a module only shows the submodules the user's role
     * already opens, and Filament drops a module left with none. Several
     * modules mix everyone's screens with Admin-only ones on purpose.
     *
     * Every labelled group carries its own module icon and every item its own
     * submodule icon, none of them shared, so the collapsed icon rail stays
     * readable (SidebarHoverNavigationTest guards the uniqueness). Filament
     * itself forbids group and item icons together; the published
     * sidebar.group view lifts that for groups whose items carry icons.
     */
    protected function buildNavigation(NavigationBuilder $builder): NavigationBuilder
    {
        return $builder->groups($this->navigationGroupsForRequest());
    }

    /**
     * Filament rebuilds the navigation every time anything asks for it: the
     * sidebar, the topbar, the module search, the favourites star and more,
     * seven times on one page load. Each build runs every submodule's
     * canAccess() and navigation-badge count queries. The groups are built
     * once per request instead (kept on the request, so every HTTP and
     * Livewire request still gets a fresh build), per panel and user, and
     * rebuilt when the user's favourites change.
     *
     * @return list<NavigationGroup>
     */
    protected function navigationGroupsForRequest(): array
    {
        $user = ($user = Filament::auth()->user()) instanceof User ? $user : null;

        $key = implode('|', [
            'fynn.navigation',
            Filament::getCurrentOrDefaultPanel()->getId(),
            $user?->getKey() ?? 'guest',
            md5((string) json_encode($user?->sidebarFavourites() ?? [])),
        ]);

        $attributes = request()->attributes;

        if (! $attributes->has($key)) {
            $attributes->set($key, $this->navigationGroups($user));
        }

        return $attributes->get($key);
    }

    /**
     * @return list<NavigationGroup>
     */
    protected function navigationGroups(?User $user): array
    {
        return SidebarShortcuts::arrange(
            dashboard: NavigationGroup::make()->items($this->navigationItemsFor(Dashboard::class)),
            modules: [
                // Credit bureau (Surepass CIBIL): only for the Admin and
                // whoever the Admin grants on CIBIL Access; Settings and
                // Access are Admin only, Audit History needs the audit
                // permission. Also MIS verification and settlements (MIS /
                // Accounts roles), and files eligible for a bank other than
                // the in-house BFL products, worked by the Other Bank Support
                // role, plus that team's Admin-set targets and incentive
                // slabs (OtherBankSupportService).
                NavigationGroup::make('Credit & Accounts')->icon(Heroicon::OutlinedWallet)->items([
                    ...$this->navigationItemsFor(CibilDashboard::class),
                    ...$this->navigationItemsFor(CibilReportResource::class),
                    ...$this->navigationItemsFor(CibilRequestResource::class),
                    ...$this->navigationItemsFor(CibilSettings::class),
                    ...$this->navigationItemsFor(CibilAccessSettings::class),
                    ...$this->navigationItemsFor(CibilAuditHistory::class),
                    ...$this->navigationItemsFor(CibilEmployeeActivity::class),
                    ...$this->navigationItemsFor(AccountVerificationResource::class),
                    ...$this->navigationItemsFor(CustomerSettlementResource::class),
                    ...$this->navigationItemsFor(OtherBankSupportDashboard::class),
                    ...$this->navigationItemsFor(OtherBankSupportTargetResource::class),
                    ...$this->navigationItemsFor(OtherBankIncentiveSlabResource::class),
                ]),

                // Administrative/operational layer around the existing
                // Customer Journey — never the journey itself. Manages
                // continuity when the assigned Manager can't proceed
                // (delegation, emergency takeover, SLA escalation, permanent
                // reassignment) without ever touching Customer::assign_to
                // except through an explicit, audited reassignment. See
                // CustomerJourneyAccessService.
                NavigationGroup::make('Customer Journey Continuity')->icon(Heroicon::OutlinedLink)->items([
                    ...$this->navigationItemsFor(JourneyContinuityDashboard::class),
                    ...$this->navigationItemsFor(CustomerJourneyDelegationResource::class),
                    ...$this->navigationItemsFor(JourneyTakeoverResource::class),
                    ...$this->navigationItemsFor(PendingManagerCaseResource::class),
                    ...$this->navigationItemsFor(CustomerSlaBreachResource::class),
                    ...$this->navigationItemsFor(CustomerReassignmentResource::class),
                    ...$this->navigationItemsFor(CustomerJourneyAuditResource::class),
                ]),

                // The customer file and its follow-ups, the requests raised
                // against a customer (duplicate PAN, eligibility, edits,
                // cashback forms and their analysis) and
                // the Admin's OCR / AI document screens.
                NavigationGroup::make('Customers')->icon(Heroicon::OutlinedUserCircle)->items([
                    ...$this->navigationItemsFor(CustomerResource::class),
                    ...$this->navigationItemsFor(FollowUpResource::class),
                    ...$this->navigationItemsFor(CustomerFollowUpCalendar::class),
                    ...$this->navigationItemsFor(CustomerFollowUpOutcomes::class),
                    ...$this->navigationItemsFor(CustomerPanRequestResource::class),
                    ...$this->navigationItemsFor(CustomerEligibilityRequestResource::class),
                    ...$this->navigationItemsFor(CustomerEditRequestResource::class),
                    ...$this->navigationItemsFor(CustomerCashbackRequestResource::class),
                    ...$this->navigationItemsFor(CashbackAnalysis::class),
                    ...$this->navigationItemsFor(OcrDocumentResource::class),
                    ...$this->navigationItemsFor(AiCustomerRecordResource::class),
                    ...$this->navigationItemsFor(AiDocumentSchemaResource::class),
                ]),

                // Standalone module: each salesperson's morning commitment
                // and how the day actually went. Reads the existing customer
                // journey, login sessions and hierarchy; keeps its own
                // commitments and monthly targets, separate from the LMS
                // target/incentive dashboards in the Performance module.
                NavigationGroup::make('Daily Commitment')->icon(Heroicon::OutlinedSun)->items([
                    ...$this->navigationItemsFor(DailyCommitmentDashboard::class),
                    ...$this->navigationItemsFor(MyDailyCommitment::class),
                    ...$this->navigationItemsFor(DailyCommitmentTeamView::class),
                    ...$this->navigationItemsFor(DailyCommitmentReports::class),
                    ...$this->navigationItemsFor(MonthlyCommitmentTargetResource::class),
                ]),

                // Every role raises and follows tickets; only the Admin sees
                // the two settings screens (categories / routing, priorities
                // / SLA).
                NavigationGroup::make('Help Desk')->icon(Heroicon::OutlinedTicket)->items([
                    ...$this->navigationItemsFor(ComplaintResource::class),
                    ...$this->navigationItemsFor(ComplaintCategoryResource::class),
                    ...$this->navigationItemsFor(ComplaintPriorityResource::class),
                ]),

                // Lead assignment monitoring is Team Leader and above.
                NavigationGroup::make('Leads')->icon(Heroicon::OutlinedFunnel)->items([
                    ...$this->navigationItemsFor(LeadResource::class),
                    ...$this->navigationItemsFor(AssignedLeadResource::class),
                    ...$this->navigationItemsFor(AssignedLeadFollowUpCalendar::class),
                    ...$this->navigationItemsFor(LeadFollowUpOutcomes::class),
                    ...$this->navigationItemsFor(LeadAssignmentReportResource::class),
                ]),

                // Everyone's own screens (hierarchy, teams, login log), the
                // Admin's people screens and the Admin-maintained lists
                // behind the Employee and User forms.
                NavigationGroup::make('People & Organisation')->icon(Heroicon::OutlinedBuildingOffice)->items([
                    ...$this->navigationItemsFor(EmployeeHierarchy::class),
                    ...$this->navigationItemsFor(TeamResource::class),
                    ...$this->navigationItemsFor(UserLoginSessionResource::class),
                    ...$this->navigationItemsFor(EmployeeResource::class),
                    ...$this->navigationItemsFor(UserResource::class),
                    ...$this->navigationItemsFor(ActivityLogResource::class),
                    // Admin only: leads / customers downloads, each approved
                    // with the owner's authenticator code.
                    ...$this->navigationItemsFor(DataDownload::class),
                    ...$this->navigationItemsFor(DesignationResource::class),
                    ...$this->navigationItemsFor(PositionResource::class),
                    ...$this->navigationItemsFor(TargetCategoryResource::class),
                    ...$this->navigationItemsFor(CostCenterResource::class),
                    ...$this->navigationItemsFor(UnitResource::class),
                    ...$this->navigationItemsFor(RoleResource::class),
                ]),

                NavigationGroup::make('Performance')->icon(Heroicon::OutlinedBolt)->items([
                    ...$this->navigationItemsFor(EmployeePerformanceDashboard::class),
                    ...$this->navigationItemsFor(EmployeePerformanceReportResource::class),
                    ...$this->navigationItemsFor(TeamPerformance::class),
                    ...$this->navigationItemsFor(FollowUpMonitor::class),
                    ...$this->navigationItemsFor(PerformanceMetricRatioResource::class),
                ]),

                NavigationGroup::make('Settings')->icon(Heroicon::OutlinedCog6Tooth)->items([
                    ...$this->navigationItemsFor(CityResource::class),
                    ...$this->navigationItemsFor(LoginPageSettings::class),
                    ...$this->navigationItemsFor(DashboardGreetingSettings::class),
                    ...$this->navigationItemsFor(AnnouncementResource::class),
                    // Voting & Feedback submodule (own tables, prompt and
                    // screens — not part of Announcements): raise polls (Admin
                    // + supervisors), define poll types (Admin), vote
                    // (everyone).
                    ...$this->navigationItemsFor(PollResource::class),
                    ...$this->navigationItemsFor(PollTypeResource::class),
                    ...$this->navigationItemsFor(MyVotes::class),
                ]),
            ],
            user: $user,
        );
    }

    /**
     * getNavigationItems() builds a resource/page's item(s) unconditionally —
     * unlike the auto-discovery path (registerNavigationItems()), it skips
     * both shouldRegisterNavigation() and canAccess(). Both are replicated
     * here so role-gated items (e.g. Employees, Users, Activity Logs) stay
     * hidden from users who shouldn't see them, exactly as before.
     *
     * An IT login additionally keeps only the two modules listed in
     * ItModuleAccess; Filament drops the groups that end up empty.
     *
     * @param  class-string  $class
     * @return array<NavigationItem>
     */
    protected function navigationItemsFor(string $class): array
    {
        if (ItModuleAccess::restricts(Filament::auth()->user()) && ! ItModuleAccess::allowsNavigationOf($class)) {
            return [];
        }

        if (! $class::shouldRegisterNavigation()) {
            return [];
        }

        if (! $class::canAccess()) {
            return [];
        }

        return $class::getNavigationItems();
    }

    public function boot(): void
    {
        $this->configureSearchableDropdowns();
        $this->configureFilterApplyBehaviour();
        $this->configurePaginationOptions();
        $this->configureColumnHeaderFilters();

        FilamentAsset::register([
            Js::make(
                'column-header-filter',
                resource_path('js/column-header-filter.js')
            ),
            Css::make(
                'column-header-filters',
                resource_path('css/column-header-filters.css')
            ),
            Js::make(
                'login-session-heartbeat',
                resource_path('js/login-session-heartbeat.js')
            ),
            Js::make(
                'page-transitions',
                resource_path('js/page-transitions.js')
            ),
            Js::make(
                'table-row-actions',
                resource_path('js/table-row-actions.js')
            ),
            Js::make(
                'table-scroll-nav',
                resource_path('js/table-scroll-nav.js')
            ),
            Js::make(
                'sidebar-hover-expand',
                resource_path('js/sidebar-hover-expand.js')
            ),
            Js::make(
                'sidebar-accordion',
                resource_path('js/sidebar-accordion.js')
            ),
            Js::make(
                'listing-sticky-header',
                resource_path('js/listing-sticky-header.js')
            ),
        ]);

        FilamentView::registerRenderHook(
            'panels::head.end',
            fn (): string => Blade::render(
                '<meta name="csrf-token" content="{{ csrf_token() }}">'
            ),
        );

        /*
         * Idle-logout configuration for login-session-heartbeat.js.
         *
         * Emitted for this panel and its /demo copy only: the Academy
         * portal shares the globally-registered heartbeat script, and the
         * script treats a missing tag as "idle logout is off", so it keeps
         * its screen-time tracking without inheriting the LMS's timeout. The
         * logout URL is read from the panel being rendered rather than
         * hardcoded, so this survives the panel moving off /admin.
         */
        FilamentView::registerRenderHook(
            'panels::head.end',
            function (): string {
                if (! in_array(Filament::getCurrentPanel()?->getId(), ['admin', 'demo'], true)) {
                    return '';
                }

                if (! UserLoginSession::idleTimeoutEnabled()) {
                    return '';
                }

                return Blade::render(
                    '<meta name="idle-timeout-seconds" content="{{ $seconds }}">'
                    .'<meta name="idle-warning-seconds" content="{{ $warning }}">'
                    .'<meta name="idle-logout-url" content="{{ $logoutUrl }}">',
                    [
                        'seconds' => UserLoginSession::idleTimeoutMinutes() * 60,
                        'warning' => max(0, (int) config('session.idle_warning', 60)),
                        'logoutUrl' => Filament::getLogoutUrl(),
                    ],
                );
            },
        );

        FilamentView::registerRenderHook(
            PanelsRenderHook::PAGE_HEADER_ACTIONS_BEFORE,
            function (array $scopes): string {
                if (in_array(Dashboard::class, $scopes, true)) {
                    return '';
                }

                return Blade::render(<<<'BLADE'
                    <x-filament::button
                        icon="heroicon-o-arrow-left"
                        color="gray"
                        outlined
                        x-on:click="window.history.back()"
                    >
                        Back
                    </x-filament::button>
                BLADE);
            },
        );
    }

    /**
     * Give every dropdown in the panel a type-to-filter search box so long
     * option lists (employees, banks, cities, statuses) never have to be
     * scrolled through one option at a time.
     *
     * Registered as component defaults, so any explicit `->searchable(...)`
     * or `->preload(...)` chained on an individual component still wins.
     */
    protected function configureSearchableDropdowns(): void
    {
        Select::configureUsing(function (Select $select): void {
            $select->searchable();
        });

        SelectFilter::configureUsing(function (SelectFilter $filter): void {
            // Ternary filters only ever hold three options; a search box
            // there would be noise rather than help.
            if ($filter instanceof TernaryFilter) {
                return;
            }

            $filter->searchable()->preload();
        });
    }

    /**
     * One "records per page" list for every table: 5 / 10 / 50 / 100 / All.
     *
     * Registered as the component default, so a table can still override
     * it with its own ->paginated([...]) — but then the top-of-toolbar
     * selector (which reads TablePaginationOptions::OPTIONS) would no
     * longer match the bottom one, so don't.
     */
    protected function configurePaginationOptions(): void
    {
        Table::configureUsing(function (Table $table): void {
            $table->paginated(TablePaginationOptions::OPTIONS);
        });
    }

    /**
     * Excel-style filters in every listing's column headers (all panels).
     *
     * The state lives on the listing's Livewire component (the
     * HasColumnHeaderFilters trait); here it is applied as a query scope —
     * so pagination, counts, exports and "select all" honour it — and every
     * filterable column header gets its funnel, while the toolbar gets the
     * active-filter chips and the shared popover. Components without the
     * trait, and columns without a header filter, render exactly as before.
     */
    protected function configureColumnHeaderFilters(): void
    {
        Table::configureUsing(function (Table $table): void {
            $table->modifyQueryUsing(
                fn (Builder|Relation $query, HasTable $livewire, bool $isResolvingRecord): Builder|Relation => $isResolvingRecord
                    // A row action must still find its record after the
                    // filter changes under it.
                    ? $query
                    : ColumnHeaderFilters::applyToQuery($livewire, $query),
            );
        });

        FilamentView::registerRenderHook(
            TablesRenderHook::HEADER_CELL,
            fn (array $data): string => ($data['column'] ?? null) instanceof Column
                ? ColumnHeaderFilters::renderHeaderCell($data['column'], (bool) ($data['isReordering'] ?? false))
                : '',
        );

        FilamentView::registerRenderHook(
            TablesRenderHook::TOOLBAR_AFTER,
            fn (): string => ColumnHeaderFilters::renderToolbar(),
        );
    }

    /**
     * Applying a filter should hand the user straight back to the results.
     *
     * Filament leaves the filter panel open on apply, so with the number of
     * filters these listings carry the user is left staring at the filter
     * form and has to close it and scroll back up to see what changed. The
     * apply button now closes the panel it lives in and brings the listing
     * into view.
     */
    protected function configureFilterApplyBehaviour(): void
    {
        // `close()` comes from the Alpine component wrapping the filter panel
        // -- `filamentDropdown` or `filamentModal`, depending on the layout.
        // It is read off `$data` (the merged Alpine scope) rather than called
        // as a bare identifier, because an inline filters layout has neither,
        // and a bare `close` would silently resolve to `window.close`.
        $handler = <<<'JS'
            $data.close?.();
            $nextTick(() => {
                const listing = $el.closest('.fi-ta');

                if (! listing) {
                    return;
                }

                const top = listing.getBoundingClientRect().top;

                if (top >= 0 && top <= window.innerHeight * 0.5) {
                    return;
                }

                listing.scrollIntoView({
                    behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
                    block: 'start',
                });
            });
            JS;

        Table::configureUsing(function (Table $table) use ($handler): void {
            $table->filtersApplyAction(
                fn (Action $action): Action => $action
                    ->alpineClickHandler($handler)
                    // alpineClickHandler() turns the wire:click off on the
                    // assumption that the Alpine expression replaces it; here
                    // it only runs alongside, so the filters still need to be
                    // applied on the server.
                    ->livewireClickHandlerEnabled(true),
            );
        });
    }
}
