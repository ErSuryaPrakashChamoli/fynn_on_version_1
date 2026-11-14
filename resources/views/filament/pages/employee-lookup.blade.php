@use('App\Enums\EmployeeLookupSection')
@use('App\Models\Employee')
@use('App\Support\EmployeeLookupAccess')

<x-filament-panels::page>
    @php
        $section = $this->currentSection();
    @endphp

    {{-- Who, over what period, and whether people who were not on the rolls in it show up. --}}
    <div class="fynn-card">
        {{ $this->form }}
        @if ($employee)
            @php
                $analysisPeriod = $this->analysisPeriod();
                $periodNotes = [];

                if (! $analysisPeriod->isSingleMonth()) {
                    $periodNotes[] = 'Targets and incentives add up the '.count($analysisPeriod->months()).' months in it, month by month'
                        .($analysisPeriod->hasPartialMonths() ? ' (a month the range only partly covers still counts its whole target and incentive)' : '')
                        .'; achievement and every list use the exact dates.';
                }

                $periodNotes[] = $includeInactive
                    ? 'Employees who were not on the rolls in this period are included.'
                    : 'Only employees on the rolls on at least one day of this period are shown.';
            @endphp
            <p class="fynn-note">
                <x-filament::icon icon="heroicon-m-calendar-days" />
                <span>
                    Analysing <span class="font-semibold text-gray-700 dark:text-gray-200">{{ $this->periodLabel() }}</span>.
                    {{ implode(' ', $periodNotes) }}
                </span>
            </p>
        @endif
    </div>

    @if (! $employee)
        <div class="fynn-empty">
            @if (filled($employeeId) || request()->filled('employee'))
                <span class="fynn-empty-icon"><x-filament::icon icon="heroicon-o-user-minus" /></span>
                <span class="fynn-empty-title">No profile to show</span>
                <span>That employee was not found, or is outside your team.</span>
            @else
                <span class="fynn-empty-icon"><x-filament::icon icon="heroicon-o-magnifying-glass" /></span>
                <span class="fynn-empty-title">Find an employee</span>
                <span>Search an employee above by name or employee ID to open their profile.</span>
            @endif
        </div>
    @else
        @php
            $roleLabel = Employee::designationOptions()[$employee->designation] ?? '—';
            $roleColor = Employee::designationColorClass($employee->designation);
            $isExited = EmployeeLookupAccess::isExited($employee);
        @endphp

        {{-- Who this is: shown on every view so the page never loses its subject. --}}
        <div class="fynn-hero">
            <span @class(['fynn-avatar fynn-avatar--lg', 'fynn-avatar--muted' => $isExited])>
                {{ $employee->initials }}
            </span>

            <div class="fynn-hero-main">
                <div class="fynn-hero-title">
                    <span>{{ $employee->emp_name }}</span>
                    <span class="dc-level {{ $roleColor }}">{{ $roleLabel }}</span>
                </div>
                <div class="fynn-hero-meta">
                    <span class="fynn-chip">
                        <x-filament::icon icon="heroicon-m-identification" />
                        ID: {{ $employee->emp_id ?: '—' }}
                    </span>
                    <span class="fynn-chip">
                        <x-filament::icon icon="heroicon-m-arrow-up-right" />
                        Reports to {{ \App\Support\EmployeeOptions::reportingBossLabel($employee) ?? 'nobody' }}
                    </span>
                </div>
            </div>

            <div class="fynn-hero-aside">
                <x-filament::badge :color="$isExited ? 'danger' : 'success'">
                    {{ $isExited ? 'Exited' : 'Active' }}
                </x-filament::badge>
            </div>
        </div>

        {{-- Quick links to the main views; the header "Actions" menu holds every view. --}}
        <nav class="flex flex-wrap gap-2" aria-label="Employee views">
            @foreach (EmployeeLookupSection::cases() as $case)
                @continue (! $case->inQuickLinks() && $case !== $section)

                <x-filament::button
                    tag="a"
                    size="xs"
                    :href="$this->sectionUrl($case)"
                    :icon="$case->icon()"
                    :color="$case === $section ? 'primary' : 'gray'"
                    :outlined="$case !== $section"
                >
                    {{ $case->label() }}
                </x-filament::button>
            @endforeach
        </nav>

        @if ($section === EmployeeLookupSection::Profile)
            @php $achievement = $this->achievement; @endphp

            <x-filament::section heading="At a glance" icon="heroicon-o-presentation-chart-line" :description="'For '.$this->periodLabel().'. Click a figure to see how it is calculated.'">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <x-employee-lookup.stat label="Target" :value="indianCurrencyFormat($achievement['target'])" figure="target" icon="heroicon-o-flag" accent="#3b82f6" />
                    <x-employee-lookup.stat label="Net Achievement" :value="indianCurrencyFormat($achievement['count_achievement'])" figure="net" icon="heroicon-o-banknotes" accent="#22c55e" />
                    <x-employee-lookup.stat label="Achievement %" :value="number_format($achievement['percentage'], 1).'%'" figure="percentage" icon="heroicon-o-chart-pie" accent="#8b5cf6" />
                    <x-employee-lookup.stat label="Incentive" :value="indianCurrencyFormat($achievement['incentive'])" figure="incentive" icon="heroicon-o-gift" accent="#f59e0b" />
                </div>
            </x-filament::section>

            <x-filament::section heading="Profile" icon="heroicon-o-identification">
                <dl class="fynn-dl">
                    @foreach ($this->profileDetails as $label => $value)
                        <div class="fynn-dl-item">
                            <dt>{{ $label }}</dt>
                            <dd>{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </x-filament::section>
        @elseif ($section === EmployeeLookupSection::Achievement)
            @php $achievement = $this->achievement; @endphp

            <x-filament::section heading="Target & Achievement" icon="heroicon-o-trophy" :description="'For '.$this->periodLabel().' — worked out by the same engine as the dashboard incentive cards. Click any figure to see how it is calculated.'">
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                    <x-employee-lookup.stat label="Target" :value="indianCurrencyFormat($achievement['target'])" figure="target" icon="heroicon-o-flag" accent="#3b82f6" />
                    <x-employee-lookup.stat label="Gross Achievement" :value="indianCurrencyFormat($achievement['actual'])" figure="gross" icon="heroicon-o-arrow-trending-up" accent="#0ea5e9" />
                    <x-employee-lookup.stat label="Net Achievement" :value="indianCurrencyFormat($achievement['count_achievement'])" figure="net" icon="heroicon-o-banknotes" accent="#22c55e" />
                    <x-employee-lookup.stat label="Achievement %" :value="number_format($achievement['percentage'], 1).'%'" figure="percentage" icon="heroicon-o-chart-pie" accent="#8b5cf6" />
                    <x-employee-lookup.stat label="Remaining to Target" :value="indianCurrencyFormat($achievement['remaining'])" figure="remaining" icon="heroicon-o-arrows-right-left" accent="#f97316" />
                    <x-employee-lookup.stat label="Target Category" :value="\App\Models\TargetCategory::labelFor($achievement['target_category']) ?? '—'" figure="category" hint="What does this mean?" icon="heroicon-o-tag" accent="#64748b" />
                </div>
            </x-filament::section>

            <x-filament::section heading="Deductions" icon="heroicon-o-receipt-percent">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach (['cashback' => 'Cashback', 'subvention' => 'Subvention', 'docking' => 'Docking'] as $key => $label)
                        <x-employee-lookup.stat :label="$label" :value="indianCurrencyFormat($achievement[$key])" :figure="$key" accent="#ef4444">
                            <div class="text-xs text-gray-500 dark:text-gray-400">
                                BFL Prime · Growth · SOL {{ indianCurrencyFormat($achievement[$key.'_bfl']) }}
                                &middot; Other banks {{ indianCurrencyFormat($achievement[$key.'_other']) }}
                            </div>
                        </x-employee-lookup.stat>
                    @endforeach
                    <x-employee-lookup.stat label="Total Deductions" :value="indianCurrencyFormat($achievement['deductions'])" figure="deductions" icon="heroicon-o-minus-circle" accent="#dc2626" />
                </div>
            </x-filament::section>

            <x-filament::section heading="Incentive" icon="heroicon-o-gift">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-employee-lookup.stat label="Incentive Earned" :value="indianCurrencyFormat($achievement['incentive'])" figure="incentive" large icon="heroicon-o-gift" accent="#f59e0b" />
                    @if ($achievement['next_slab'])
                        <x-employee-lookup.stat label="Next Slab" :value="indianCurrencyFormat($achievement['next_slab']['incentive'])" figure="incentive" hint="See every slab" large icon="heroicon-o-arrow-up-circle" accent="#22c55e">
                            <div class="text-sm text-gray-700 dark:text-gray-300">
                                {{ indianCurrencyFormat($achievement['next_slab']['remaining']) }} more net achievement reaches the
                                {{ indianCurrencyFormat($achievement['next_slab']['target']) }} slab.
                            </div>
                        </x-employee-lookup.stat>
                    @endif
                </div>
            </x-filament::section>
        @elseif ($section === EmployeeLookupSection::Performance)
            @php $metrics = $this->performance; @endphp

            <x-filament::section heading="OTP & Funnel" icon="heroicon-o-funnel" :description="'For '.$this->periodLabel().'. Team figures roll up everyone under this employee. Click a card to see its list.'">
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                    @foreach ([
                        ['label' => 'Total OTP', 'value' => number_format($metrics['otp_count']), 'section' => EmployeeLookupSection::Customers, 'icon' => 'heroicon-o-device-phone-mobile', 'accent' => '#eab308'],
                        ['label' => 'Eligible OTP', 'value' => number_format($metrics['eligible_otp_count']), 'section' => EmployeeLookupSection::EligibleOtp, 'icon' => 'heroicon-o-check-badge', 'accent' => '#22c55e'],
                        ['label' => 'Not Eligible OTP', 'value' => number_format($metrics['not_eligible_otp_count']), 'section' => EmployeeLookupSection::NotEligibleOtp, 'icon' => 'heroicon-o-no-symbol', 'accent' => '#ef4444'],
                        ['label' => 'Logins', 'value' => number_format($metrics['login_count']), 'section' => EmployeeLookupSection::CaseLogins, 'icon' => 'heroicon-o-document-arrow-up', 'accent' => '#3b82f6'],
                        ['label' => 'Approvals', 'value' => number_format($metrics['approval_count']), 'section' => EmployeeLookupSection::Approvals, 'icon' => 'heroicon-o-hand-thumb-up', 'accent' => '#10b981'],
                        ['label' => 'Disbursals', 'value' => number_format($metrics['disbursal_count']), 'section' => EmployeeLookupSection::Disbursals, 'icon' => 'heroicon-o-banknotes', 'accent' => '#0d9488'],
                        ['label' => 'Disbursal Amount', 'value' => indianCurrencyFormat($metrics['disbursal_amount']), 'section' => EmployeeLookupSection::Disbursals, 'icon' => 'heroicon-o-currency-rupee', 'accent' => '#0d9488'],
                        ['label' => 'Dropped', 'value' => number_format($metrics['dropped_count']), 'section' => EmployeeLookupSection::Dropped, 'icon' => 'heroicon-o-arrow-down-tray', 'accent' => '#64748b'],
                        ['label' => 'Not Approved', 'value' => number_format($metrics['not_approved_count']), 'section' => EmployeeLookupSection::NotApproved, 'icon' => 'heroicon-o-x-circle', 'accent' => '#f43f5e'],
                        ['label' => 'Present Days', 'value' => number_format($metrics['present_days']).' / '.number_format($metrics['working_days']), 'section' => EmployeeLookupSection::Logins, 'icon' => 'heroicon-o-calendar-days', 'accent' => '#8b5cf6'],
                        ['label' => 'Screen Time', 'value' => number_format($metrics['screen_time_hours'], 1).' h', 'section' => EmployeeLookupSection::Logins, 'icon' => 'heroicon-o-clock', 'accent' => '#6366f1'],
                    ] as $stat)
                        <x-employee-lookup.stat :label="$stat['label']" :value="$stat['value']" :href="$this->sectionUrl($stat['section'])" :icon="$stat['icon']" :accent="$stat['accent']" />
                    @endforeach
                </div>
            </x-filament::section>
        @elseif ($section === EmployeeLookupSection::Hierarchy)
            @php $hierarchy = $this->hierarchy; @endphp

            <x-filament::section heading="Reports up to" description="Every boss above this employee, top first." icon="heroicon-o-arrow-up-circle">
                <div class="fynn-list">
                    @forelse ($hierarchy['chain'] as $boss)
                        @continue (! $boss['active'] && ! $includeInactive)
                        <div class="fynn-list-row">
                            <div class="fynn-list-main">
                                <span class="fynn-avatar fynn-avatar--sm">{{ \Illuminate\Support\Str::of($boss['name'])->explode(' ')->filter()->take(2)->map(fn ($part) => mb_substr($part, 0, 1))->implode('') }}</span>
                                <div class="min-w-0">
                                    <div class="fynn-list-title">
                                        @if ($boss['url'])
                                            <x-filament::link :href="$boss['url']">{{ $boss['name'] }}</x-filament::link>
                                        @else
                                            {{ $boss['name'] }}
                                        @endif
                                    </div>
                                    <div class="fynn-list-meta">{{ $boss['emp_id'] }} &middot; {{ $boss['designation'] }}</div>
                                </div>
                            </div>
                            @if ($boss['exited'])
                                <x-filament::badge color="danger" size="sm">Exited</x-filament::badge>
                            @endif
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">Nobody — this employee sits at the top of their branch.</p>
                    @endforelse
                </div>
            </x-filament::section>

            <x-filament::section heading="Direct reports" :description="collect($hierarchy['reports'])->where('active', true)->count().' on the rolls in this period, of '.count($hierarchy['reports']).' reporting straight to this employee today.'" icon="heroicon-o-user-group">
                <div class="fynn-list">
                    @forelse (collect($hierarchy['reports'])->filter(fn (array $report): bool => $report['active'] || $includeInactive) as $report)
                        <div class="fynn-list-row">
                            <div class="fynn-list-main">
                                <span @class(['fynn-avatar fynn-avatar--sm', 'fynn-avatar--muted' => ! $report['active'] || $report['exited']])>{{ \Illuminate\Support\Str::of($report['name'])->explode(' ')->filter()->take(2)->map(fn ($part) => mb_substr($part, 0, 1))->implode('') }}</span>
                                <div class="min-w-0">
                                    <div class="fynn-list-title">
                                        @if ($report['url'])
                                            <x-filament::link :href="$report['url']">{{ $report['name'] }}</x-filament::link>
                                        @else
                                            {{ $report['name'] }}
                                        @endif
                                    </div>
                                    <div class="fynn-list-meta">{{ $report['emp_id'] }} &middot; {{ $report['designation'] }}</div>
                                </div>
                            </div>
                            <div class="flex flex-wrap items-center justify-end gap-2">
                                @if ($report['team'] > 0)
                                    <span class="fynn-chip"><x-filament::icon icon="heroicon-m-users" />Team of {{ $report['team'] }}</span>
                                @endif
                                @if (! $report['active'])
                                    <x-filament::badge color="gray" size="sm">Not active in period</x-filament::badge>
                                @elseif ($report['exited'])
                                    <x-filament::badge color="danger" size="sm">Exited</x-filament::badge>
                                @endif
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500">
                            Nobody reporting to this employee was on the rolls in this period.
                            @if (! $includeInactive) Choose "Include inactive" at the top to see everyone. @endif
                        </p>
                    @endforelse
                </div>
            </x-filament::section>

            @if ($hierarchy['levels'] !== [])
                <x-filament::section heading="Team down the line" icon="heroicon-o-rectangle-group">
                    <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                        @foreach ($hierarchy['levels'] as $level)
                            <x-employee-lookup.stat :label="$level['label']" :value="$level['active'].' active'" icon="heroicon-o-users">
                                @if ($level['exited'] > 0)
                                    <div class="text-xs text-gray-500">{{ $level['exited'] }} exited</div>
                                @endif
                            </x-employee-lookup.stat>
                        @endforeach
                    </div>
                </x-filament::section>
            @endif
        @else
            {{ $this->table }}
        @endif
    @endif

    {{--
        Filament draws a table page's action-modal container inside the
        table only. The figure views draw no table, so the "how is this
        calculated?" pop-up needs this one or a click on a card has nowhere
        to open. On a list view the table has already drawn it, and
        Filament's own render-once flag makes this a no-op.
    --}}
    <x-filament-actions::modals />
</x-filament-panels::page>
