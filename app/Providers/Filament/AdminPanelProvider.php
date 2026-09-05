<?php

namespace App\Providers\Filament;

use App\Filament\Pages\AssignedLeadFollowUpCalendar;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\ChangePassword;
use App\Filament\Pages\CustomerFollowUpCalendar;
use App\Filament\Pages\DailyCommitmentDashboard;
use App\Filament\Pages\DailyCommitmentReports;
use App\Filament\Pages\DailyCommitmentTeamView;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\DashboardGreetingSettings;
use App\Filament\Pages\EmployeeHierarchy;
use App\Filament\Pages\EmployeePerformanceDashboard;
use App\Filament\Pages\JourneyContinuityDashboard;
use App\Filament\Pages\LoginPageSettings;
use App\Filament\Pages\MyDailyCommitment;
use App\Filament\Pages\MyProfile;
use App\Filament\Pages\TeamPerformance;
use App\Filament\Resources\AccountVerifications\AccountVerificationResource;
use App\Filament\Resources\ActivityLogs\ActivityLogResource;
use App\Filament\Resources\AiCustomerRecords\AiCustomerRecordResource;
use App\Filament\Resources\AiDocumentSchemas\AiDocumentSchemaResource;
use App\Filament\Resources\AssignedLeads\AssignedLeadResource;
use App\Filament\Resources\Cities\CityResource;
use App\Filament\Resources\CustomerJourneyAudits\CustomerJourneyAuditResource;
use App\Filament\Resources\CustomerJourneyDelegations\CustomerJourneyDelegationResource;
use App\Filament\Resources\CustomerPanRequests\CustomerPanRequestResource;
use App\Filament\Resources\CustomerReassignments\CustomerReassignmentResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\CustomerSettlements\CustomerSettlementResource;
use App\Filament\Resources\CustomerSlaBreaches\CustomerSlaBreachResource;
use App\Filament\Resources\EmployeePerformanceReports\EmployeePerformanceReportResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\FollowUps\FollowUpResource;
use App\Filament\Resources\JourneyTakeovers\JourneyTakeoverResource;
use App\Filament\Resources\LeadAssignmentReports\LeadAssignmentReportResource;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\MonthlyCommitmentTargets\MonthlyCommitmentTargetResource;
use App\Filament\Resources\OcrDocuments\OcrDocumentResource;
use App\Filament\Resources\PendingManagerCases\PendingManagerCaseResource;
use App\Filament\Resources\PerformanceMetricRatios\PerformanceMetricRatioResource;
use App\Filament\Resources\Teams\TeamResource;
use App\Filament\Resources\UserLoginSessions\UserLoginSessionResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Widgets\CustomerStats;
use App\Filament\Widgets\DailyCommitmentStats;
use App\Filament\Widgets\IncentiveStats;
use App\Filament\Widgets\ManagerPPPStats;
use App\Filament\Widgets\PerformanceStats;
use App\Filament\Widgets\TargetStats;
use App\Http\Middleware\EncryptCookies;
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
use Filament\Support\Assets\Js;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\Support\Facades\FilamentAsset;
use Filament\Support\Facades\FilamentView;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Filament\Tables\View\TablesRenderHook;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Saade\FilamentFullCalendar\FilamentFullCalendarPlugin;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
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
                '*/admin/profile',
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
            ->sidebarFullyCollapsibleOnDesktop()
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
             * ProcessOcrDocument::failed().
             */
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->plugin(
                FilamentFullCalendarPlugin::make()
                    ->selectable()
                    ->editable(false)
            )
            ->renderHook(
                // PanelsRenderHook::TOPBAR_START,
                PanelsRenderHook::GLOBAL_SEARCH_BEFORE,
                function () {
                    return Blade::render('@livewire("top-performer-marquee")');
                }
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
            // A "back to top" button after every resource List page's
            // table, for jumping back up past a long page of rows without
            // hunting for the scrollbar. Scoped to List pages specifically
            // (not relation-manager tables) via this hook.
            ->renderHook(
                PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER,
                fn (): string => view('filament.components.table-scroll-to-top')->render(),
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

            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
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
     * Filament's default navigation puts every ungrouped item in one block
     * ahead of all named groups, which can't produce the merged, explicitly
     * ordered sidebar this app wants (e.g. "Leads" between "Dashboard" and
     * "Customers"). Building it by hand lets standalone items and groups be
     * interleaved freely — each item still comes from its own resource/page
     * via getNavigationItems(), so icons, badges and sort order stay intact.
     */
    protected function buildNavigation(NavigationBuilder $builder): NavigationBuilder
    {
        return $builder->groups([
            NavigationGroup::make()->items($this->navigationItemsFor(Dashboard::class)),

            NavigationGroup::make('Leads')->items([
                ...$this->navigationItemsFor(LeadResource::class),
                ...$this->navigationItemsFor(AssignedLeadResource::class),
                ...$this->navigationItemsFor(AssignedLeadFollowUpCalendar::class),
            ]),

            NavigationGroup::make('Customers')->items([
                ...$this->navigationItemsFor(CustomerResource::class),
                ...$this->navigationItemsFor(FollowUpResource::class),
                ...$this->navigationItemsFor(CustomerFollowUpCalendar::class),
            ]),

            // Administrative/operational layer around the existing Customer
            // Journey above — never the journey itself. Manages continuity
            // when the assigned Manager can't proceed (delegation, emergency
            // takeover, SLA escalation, permanent reassignment) without ever
            // touching Customer::assign_to except through an explicit,
            // audited reassignment. See CustomerJourneyAccessService.
            NavigationGroup::make('Customer Journey Continuity')->items([
                ...$this->navigationItemsFor(JourneyContinuityDashboard::class),
                ...$this->navigationItemsFor(CustomerJourneyDelegationResource::class),
                ...$this->navigationItemsFor(JourneyTakeoverResource::class),
                ...$this->navigationItemsFor(PendingManagerCaseResource::class),
                ...$this->navigationItemsFor(CustomerSlaBreachResource::class),
                ...$this->navigationItemsFor(CustomerReassignmentResource::class),
                ...$this->navigationItemsFor(CustomerJourneyAuditResource::class),
            ]),

            NavigationGroup::make('Performance')->items([
                ...$this->navigationItemsFor(EmployeePerformanceDashboard::class),
                ...$this->navigationItemsFor(EmployeePerformanceReportResource::class),
                ...$this->navigationItemsFor(TeamPerformance::class),
                ...$this->navigationItemsFor(PerformanceMetricRatioResource::class),
            ]),

            // Standalone module: each salesperson's morning commitment and
            // how the day actually went. Reads the existing customer
            // journey, login sessions and hierarchy; keeps its own
            // commitments and monthly targets, separate from the LMS
            // target/incentive dashboards in the Performance group above.
            NavigationGroup::make('Daily Commitment')->items([
                ...$this->navigationItemsFor(DailyCommitmentDashboard::class),
                ...$this->navigationItemsFor(MyDailyCommitment::class),
                ...$this->navigationItemsFor(DailyCommitmentTeamView::class),
                ...$this->navigationItemsFor(DailyCommitmentReports::class),
                ...$this->navigationItemsFor(MonthlyCommitmentTargetResource::class),
            ]),

            NavigationGroup::make('Administration')->items([
                ...$this->navigationItemsFor(EmployeeHierarchy::class),
                ...$this->navigationItemsFor(TeamResource::class),
            ]),

            NavigationGroup::make('Lead Assignment')->items(
                $this->navigationItemsFor(LeadAssignmentReportResource::class),
            ),

            NavigationGroup::make('Accounts')->items([
                ...$this->navigationItemsFor(AccountVerificationResource::class),
                ...$this->navigationItemsFor(CustomerSettlementResource::class),
            ]),

            NavigationGroup::make('Documents')->items([
                ...$this->navigationItemsFor(OcrDocumentResource::class),
                ...$this->navigationItemsFor(AiCustomerRecordResource::class),
                ...$this->navigationItemsFor(AiDocumentSchemaResource::class),
            ]),

            NavigationGroup::make('People & Access')->items([
                ...$this->navigationItemsFor(EmployeeResource::class),
                ...$this->navigationItemsFor(UserResource::class),
                ...$this->navigationItemsFor(ActivityLogResource::class),
                ...$this->navigationItemsFor(UserLoginSessionResource::class),
            ]),

            NavigationGroup::make('Request')->items(
                $this->navigationItemsFor(CustomerPanRequestResource::class),
            ),

            NavigationGroup::make('Setting')->items([
                ...$this->navigationItemsFor(CityResource::class),
                ...$this->navigationItemsFor(LoginPageSettings::class),
                ...$this->navigationItemsFor(DashboardGreetingSettings::class),
            ]),
        ]);
    }

    /**
     * getNavigationItems() builds a resource/page's item(s) unconditionally —
     * unlike the auto-discovery path (registerNavigationItems()), it skips
     * both shouldRegisterNavigation() and canAccess(). Both are replicated
     * here so role-gated items (e.g. Employees, Users, Activity Logs) stay
     * hidden from users who shouldn't see them, exactly as before.
     *
     * @param  class-string  $class
     * @return array<NavigationItem>
     */
    protected function navigationItemsFor(string $class): array
    {
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

        FilamentAsset::register([
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
        ]);

        FilamentView::registerRenderHook(
            'panels::head.end',
            fn (): string => Blade::render(
                '<meta name="csrf-token" content="{{ csrf_token() }}">'
            ),
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
