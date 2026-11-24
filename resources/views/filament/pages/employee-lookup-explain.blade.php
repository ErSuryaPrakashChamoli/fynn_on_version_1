@use('App\Models\Employee')

{{--
    The Employee Lookup "how is this calculated?" pop-up (EmployeeLookup::explainAction),
    for the period picked at the top of the page. Every figure comes from
    EmployeeFigureBreakdownService, which reads AchievementCalculatorService —
    the same engine as the card clicked.

    A team or company target opens on its counted callers only, with a
    table of active / inactive / counted / all callers at the top and a
    "Counted / Including not counted" switch (instantly, in the browser);
    callers with no day on the rolls in the period are not listed at all.
    The other figures keep the "Active only / Include inactive" switch:
    people not on the rolls on any day of the period are hidden until it is
    on, starting from the page's own choice. New joiners, leavers and
    movers carry a badge with the date.
--}}
@php
    $money = fn ($value): string => indianCurrencyFormat((float) $value);
    // The .fynn-table design-system class styles headers and cells; these
    // only add what a particular column needs on top of it.
    $thClass = '';
    $tdClass = '';
    $tdNumber = 'fynn-num';
    $highlight = 'bg-primary-50 dark:bg-primary-500/10';
    $multiMonth = ! $period->isSingleMonth();
    $hideUnlessInactive = fn (array $row): string => ($row['active'] ?? true) ? '' : 'x-show="showInactive" x-cloak';

    // A team or company target lists its callers with the Counted / Including not counted switch.
    $hasCallerList = $view === 'target' && in_array($target['kind'] ?? null, ['team', 'company'], true) && ($target['callers'] ?? []) !== [];
    $hideUnlessCounted = fn (array $row): string => $row['share'] > 0 ? '' : 'x-show="showNotCounted" x-cloak';

    // Every other list of people in this pop-up, for the Active / Include inactive switch.
    $people = match ($view) {
        'achievement' => [...($cases['members'] ?? []), ...($cases['rows'] ?? [])],
        'incentive' => $incentive['callers'] ?? [],
        default => [],
    };
    $inactiveCount = collect($people)->where('active', false)->count();
@endphp

<div class="space-y-6" x-data="{ showInactive: @js($includeInactive), showNotCounted: false }">
    {{-- ============================================== CALLER ANALYSIS + COUNTED SWITCH --}}
    @if ($hasCallerList)
        @php
            $callerGroups = collect($target['caller_groups']);
            $callerTotals = [
                'on_rolls' => $callerGroups->sum('on_rolls'),
                'left' => $callerGroups->sum('left'),
                'counted' => $callerGroups->sum('counted'),
                'all' => count($target['callers']),
            ];
            $asOf = $period->end->copy()->min(today());
        @endphp

        <div>
            <h4 class="fynn-subheading">Callers in {{ $period->label }}</h4>
            <div class="fynn-table-wrap overflow-x-auto">
                <table class="fynn-table">
                    <thead>
                        <tr>
                            <th>{{ $target['grouped_by_team'] ? 'Team' : '' }}</th>
                            <th class="text-end">Active</th>
                            <th class="text-end">Inactive</th>
                            <th class="text-end">Counted</th>
                            <th class="text-end">Including not counted</th>
                        </tr>
                    </thead>
                    @if ($target['grouped_by_team'])
                        <tbody>
                            @foreach ($callerGroups as $group)
                                <tr>
                                    <td>
                                        @if ($group['team_leader'] !== null)
                                            <span class="font-medium">{{ $group['team_leader']['name'] }}</span>
                                            <span class="text-xs text-gray-500 dark:text-gray-400">{{ $group['team_leader']['emp_id'] }}</span>
                                        @else
                                            <span class="font-medium">No Team Leader</span>
                                        @endif
                                    </td>
                                    <td class="{{ $tdNumber }}">{{ $group['on_rolls'] }}</td>
                                    <td @class([$tdNumber, 'text-danger-600 dark:text-danger-400' => $group['left'] > 0])>{{ $group['left'] }}</td>
                                    <td class="{{ $tdNumber }} font-semibold">{{ $group['counted'] }}</td>
                                    <td class="{{ $tdNumber }}">{{ count($group['callers']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td>All teams</td>
                                <td class="{{ $tdNumber }}">{{ $callerTotals['on_rolls'] }}</td>
                                <td class="{{ $tdNumber }}">{{ $callerTotals['left'] }}</td>
                                <td class="{{ $tdNumber }}">{{ $callerTotals['counted'] }}</td>
                                <td class="{{ $tdNumber }}">{{ $callerTotals['all'] }}</td>
                            </tr>
                        </tfoot>
                    @else
                        <tbody>
                            <tr>
                                <td class="font-medium">All callers</td>
                                <td class="{{ $tdNumber }}">{{ $callerTotals['on_rolls'] }}</td>
                                <td @class([$tdNumber, 'text-danger-600 dark:text-danger-400' => $callerTotals['left'] > 0])>{{ $callerTotals['left'] }}</td>
                                <td class="{{ $tdNumber }} font-semibold">{{ $callerTotals['counted'] }}</td>
                                <td class="{{ $tdNumber }}">{{ $callerTotals['all'] }}</td>
                            </tr>
                        </tbody>
                    @endif
                </table>
            </div>
            <p class="fynn-note">
                <span class="font-semibold">Active:</span> still on the rolls on {{ $asOf->format('d M Y') }}.
                <span class="font-semibold">Inactive:</span> left during {{ $period->label }}.
                <span class="font-semibold">Counted:</span> their target counts here.
                <span class="font-semibold">Including not counted:</span> every caller on the rolls on at least one day of {{ $period->label }}, counted or not.
            </p>
        </div>

        <div class="fynn-toolbar">
            <span class="fynn-toolbar-label">Callers</span>
            <div class="fynn-segmented" role="group" aria-label="Callers shown">
                <button
                    type="button"
                    x-on:click="showNotCounted = false"
                    x-bind:class="showNotCounted ? '' : 'is-active is-accent'"
                    x-bind:aria-pressed="(! showNotCounted).toString()"
                    class="fynn-segmented-btn"
                >Counted ({{ $callerTotals['counted'] }})</button>
                <button
                    type="button"
                    x-on:click="showNotCounted = true"
                    x-bind:class="showNotCounted ? 'is-active' : ''"
                    x-bind:aria-pressed="showNotCounted.toString()"
                    class="fynn-segmented-btn"
                >Including not counted ({{ $callerTotals['all'] }})</button>
            </div>
            <span class="text-xs text-gray-500 dark:text-gray-400">
                <span x-show="! showNotCounted">
                    {{ $callerTotals['all'] - $callerTotals['counted'] }} not-counted {{ \Illuminate\Support\Str::plural('caller', $callerTotals['all'] - $callerTotals['counted']) }} hidden — the totals always include everything.
                </span>
                <span x-show="showNotCounted" x-cloak>Showing every caller on the rolls in {{ $period->label }}, counted or not.</span>
            </span>
        </div>
    @endif

    {{-- ========================================================== EMPLOYEES SWITCH --}}
    @if ($people !== [])
        <div class="fynn-toolbar">
            <span class="fynn-toolbar-label">Employees</span>
            <div class="fynn-segmented" role="group" aria-label="Employees shown">
                <button
                    type="button"
                    x-on:click="showInactive = false"
                    x-bind:class="showInactive ? '' : 'is-active is-accent'"
                    x-bind:aria-pressed="(! showInactive).toString()"
                    class="fynn-segmented-btn"
                >Active only</button>
                <button
                    type="button"
                    x-on:click="showInactive = true"
                    x-bind:class="showInactive ? 'is-active' : ''"
                    x-bind:aria-pressed="showInactive.toString()"
                    class="fynn-segmented-btn"
                >Include inactive ({{ $inactiveCount }})</button>
            </div>
            <span class="text-xs text-gray-500 dark:text-gray-400">
                <span x-show="! showInactive">
                    {{ $inactiveCount }} {{ \Illuminate\Support\Str::plural('row', $inactiveCount) }} of people not on the rolls in {{ $period->label }} hidden — the totals always include everything.
                </span>
                <span x-show="showInactive" x-cloak>Showing everyone, including people not on the rolls in {{ $period->label }}.</span>
            </span>
        </div>
    @endif

    {{-- ================================================================ TARGET --}}
    @if ($view === 'target')
        @if ($target['kind'] === 'caller')
            <div class="fynn-callout">
                <div class="text-sm text-gray-600 dark:text-gray-400">A caller's own monthly target is the flat amount of their target category{{ $multiMonth ? ', added up for every month of the period' : '' }}.</div>
                <div class="fynn-formula">
                    Target category {{ $target['category'] ?? '(none set)' }}
                    @if ($multiMonth)
                        &rarr; {{ count($target['months']) }} months = {{ $money($target['total']) }}
                    @else
                        &rarr; {{ $money($target['total']) }}
                    @endif
                </div>
            </div>
            <ul class="fynn-rules">
                @if ($target['category_amount'] === null)
                    <li>No target amount is set for this category (or no category is assigned), so the default caller target applies.</li>
                @else
                    <li>The amount comes from Employee Setup &rarr; Target Categories ({{ $target['category'] }} = {{ $money($target['category_amount']) }} a month).</li>
                @endif
                @foreach ($target['category_notes'] ?? [] as $categoryNote)
                    <li>{{ $categoryNote }}</li>
                @endforeach
                <li>A target category change only applies from its effective date: earlier months keep the category held then.</li>
                <li>Joining and exit dates never reduce a caller's own target. They only change how much of it counts in their Team Leader's and Manager's team target.</li>
            </ul>
        @elseif (in_array($target['kind'], ['team', 'company'], true))
            <div class="fynn-callout">
                <div class="text-sm text-gray-600 dark:text-gray-400">
                    @if ($target['kind'] === 'company')
                        The company target is every caller's target added together{{ $multiMonth ? ', month by month' : '' }}.
                    @else
                        A {{ $designation }}'s target is built from the callers in their team, plus a top-up for every short-staffed Team Leader in it{{ $multiMonth ? ' — worked out for each month on that month\'s team, then added up' : '' }}.
                    @endif
                </div>
                <div class="fynn-formula">
                    {{ $money($target['callers_total']) }} callers' targets
                    @if ($target['kind'] === 'team')
                        + {{ $money($target['top_up']) }} understaffed-team top-ups
                    @endif
                    = <span class="fynn-formula-result">{{ $money($target['total']) }}</span>
                </div>
            </div>

            <ul class="fynn-rules">
                <li>Each caller brings their target category amount for a full month on the rolls.</li>
                <li>A caller who joined or left during a month brings the flat partial-month target if they were on the rolls 10 days or more that month, and nothing below 10 days.</li>
                @if ($target['kind'] === 'team')
                    <li>A caller who moved into or out of the team during the month brings their full target if they reported into it 10 days or more while on the rolls, and nothing below 10 days — so a caller moved mid-month can count in both teams. They are marked <span class="font-semibold">Moved in</span> or <span class="font-semibold">Moved out</span> with the date. A caller in the team for the whole month brings their full target.</li>
                    <li>A promotion or demotion counts from its effective date: the months before it keep the old position.</li>
                    <li>Every Team Leader in the team with fewer than 3 callers adds ₹30,00,000 that month, if they were in the team for 10 days or more while on the rolls. A caller counts towards those 3 only with 10 or more days reporting to them while on the rolls (from their joining date to their exit date), so a caller who left during the month still counts if they had 10 days.</li>
                @endif
            </ul>

            @if ($multiMonth)
                @include('filament.pages.employee-lookup-explain-months', ['rows' => $target['months'], 'columns' => ['callers' => "Callers' targets", 'top_up' => 'Top-ups', 'total' => 'Target']])
            @endif

            @php
                $callerRows = collect($target['callers']);
                $movedInCount = $callerRows->filter(fn (array $caller): bool => collect($caller['movements'] ?? [])->contains('direction', 'in'))->count();
                $movedOutCount = $callerRows->filter(fn (array $caller): bool => collect($caller['movements'] ?? [])->contains('direction', 'out'))->count();
                $newJoinerCount = $callerRows->whereNotNull('joined_during_period')->count();
                $leftCount = $callerRows->whereNotNull('left_during_period')->count();
                $periodDays = max((int) ($target['period_days'] ?? 1), 1);
            @endphp

            <div>
                <div class="mb-2.5 flex flex-wrap items-center justify-between gap-2">
                    <h4 class="fynn-subheading mb-0">Callers</h4>
                    <div class="flex flex-wrap items-center gap-1.5">
                        @if ($target['grouped_by_team'])
                            <span class="fynn-chip">{{ count($target['caller_groups']) }} {{ \Illuminate\Support\Str::plural('team', count($target['caller_groups'])) }}</span>
                        @endif
                        @if ($newJoinerCount > 0)
                            <span class="fynn-chip">{{ $newJoinerCount }} new {{ \Illuminate\Support\Str::plural('joiner', $newJoinerCount) }}</span>
                        @endif
                        @if ($leftCount > 0)
                            <span class="fynn-chip">{{ $leftCount }} left</span>
                        @endif
                        @if ($movedInCount > 0)
                            <span class="fynn-chip">{{ $movedInCount }} moved in</span>
                        @endif
                        @if ($movedOutCount > 0)
                            <span class="fynn-chip">{{ $movedOutCount }} moved out</span>
                        @endif
                    </div>
                </div>

                <div class="fynn-table-wrap overflow-x-auto">
                    <table class="fynn-table">
                        <thead>
                            <tr>
                                <th class="{{ $thClass }}">Caller</th>
                                <th class="{{ $thClass }}">DOJ / Exit</th>
                                <th class="{{ $thClass }}">Category</th>
                                <th class="{{ $thClass }}">{{ $target['kind'] === 'company' ? 'On the rolls' : 'In this team' }}</th>
                                <th class="{{ $thClass }} text-end">Counted here</th>
                                <th class="{{ $thClass }}">Why</th>
                            </tr>
                        </thead>

                        @forelse ($target['caller_groups'] as $group)
                            <tbody @if ($group['counted'] === 0) x-show="showNotCounted" x-cloak @endif>
                                @if ($target['grouped_by_team'])
                                    <tr class="fynn-table-group">
                                        <th colspan="4" scope="rowgroup">
                                            <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
                                                @if ($group['team_leader'] !== null)
                                                    <span class="fynn-avatar fynn-avatar--sm" aria-hidden="true">{{ $group['team_leader']['initials'] }}</span>
                                                    <div class="min-w-0">
                                                        <div class="font-semibold">{{ $group['team_leader']['name'] }}</div>
                                                        <div class="text-xs font-normal text-gray-500 dark:text-gray-400">Team Leader · {{ $group['team_leader']['emp_id'] ?: '—' }}</div>
                                                    </div>
                                                @else
                                                    <span class="fynn-avatar fynn-avatar--sm fynn-avatar--muted" aria-hidden="true">—</span>
                                                    <div class="min-w-0">
                                                        <div class="font-semibold">No Team Leader</div>
                                                        <div class="text-xs font-normal text-gray-500 dark:text-gray-400">Reporting straight to a Manager or above</div>
                                                    </div>
                                                @endif
                                                <div class="flex flex-wrap items-center gap-1.5">
                                                    <span class="fynn-chip">{{ count($group['callers']) }} {{ \Illuminate\Support\Str::plural('caller', count($group['callers'])) }}</span>
                                                    <span class="fynn-chip">{{ $group['counted'] }} counted</span>
                                                    @if ($group['top_up'] > 0)
                                                        <span class="fynn-chip fynn-chip--accent">+ {{ $money($group['top_up']) }} top-up</span>
                                                    @endif
                                                </div>
                                            </div>
                                        </th>
                                        <td class="{{ $tdNumber }} font-bold">{{ $money($group['total']) }}</td>
                                        <td class="text-xs font-normal text-gray-500 dark:text-gray-400">Callers' targets in this team{{ $group['top_up'] > 0 ? ', before the top-up' : '' }}</td>
                                    </tr>
                                @endif

                                @foreach ($group['callers'] as $caller)
                                    @php
                                        $isCounted = $caller['share'] > 0;
                                        $daysHere = (int) ($caller['days_here'] ?? 0);
                                    @endphp
                                    <tr {!! $hideUnlessCounted($caller) !!}>
                                        <td class="min-w-56 {{ $target['grouped_by_team'] ? 'ps-6' : '' }}">
                                            @include('filament.pages.employee-lookup-explain-person', ['person' => $caller])
                                        </td>
                                        <td class="whitespace-nowrap">
                                            <div>
                                                <span class="text-xs text-gray-500 dark:text-gray-400">DOJ</span>
                                                {{ $caller['joined_on']?->format('d M Y') ?? '—' }}
                                            </div>
                                            @if ($caller['left_on'] !== null)
                                                <div class="text-danger-600 dark:text-danger-400">
                                                    <span class="text-xs">Exit</span>
                                                    {{ $caller['left_on']->format('d M Y') }}
                                                </div>
                                            @endif
                                        </td>
                                        <td>
                                            @if (filled($caller['category']))
                                                <span class="fynn-chip">{{ $caller['category'] }}</span>
                                            @else
                                                <span class="text-gray-400">—</span>
                                            @endif
                                        </td>
                                        <td>
                                            <div class="fynn-days">
                                                <div class="fynn-days-label"><strong>{{ $daysHere }}</strong> of {{ $periodDays }} days</div>
                                                <div class="fynn-days-track">
                                                    <span class="fynn-days-fill" style="width: {{ min(round($daysHere / $periodDays * 100, 2), 100) }}%"></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td @class([$tdNumber, 'whitespace-nowrap font-semibold', 'text-gray-400 dark:text-gray-500' => ! $isCounted])>{{ $money($caller['share']) }}</td>
                                        <td class="min-w-72 text-xs leading-relaxed text-gray-600 dark:text-gray-400">{{ $caller['note'] ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        @empty
                            <tbody>
                                <tr><td colspan="6" class="py-6 text-center text-sm text-gray-500">No callers counted in this period.</td></tr>
                            </tbody>
                        @endforelse

                        <tfoot>
                            <tr>
                                <td colspan="4" class="{{ $tdClass }} font-semibold">Callers' targets</td>
                                <td class="{{ $tdNumber }} font-bold">{{ $money($target['callers_total']) }}</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            @if ($target['kind'] === 'team' && $target['team_leaders'] !== [])
                <div>
                    <h4 class="fynn-subheading">Team Leaders in this team</h4>
                    <div class="fynn-table-wrap overflow-x-auto">
                        <table class="fynn-table">
                            <thead>
                                <tr>
                                    <th class="{{ $thClass }}">Team Leader</th>
                                    @if (! $multiMonth)
                                        <th class="{{ $thClass }} text-end">Callers</th>
                                    @endif
                                    <th class="{{ $thClass }}">Top-up</th>
                                    <th class="{{ $thClass }}">Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($target['team_leaders'] as $teamLeader)
                                    <tr @class([$highlight => $teamLeader['months_understaffed'] > 0])>
                                        <td class="{{ $tdClass }}">@include('filament.pages.employee-lookup-explain-person', ['person' => $teamLeader])</td>
                                        @if (! $multiMonth)
                                            <td class="{{ $tdNumber }}">{{ $teamLeader['callers'] }}</td>
                                        @endif
                                        <td class="{{ $tdClass }}">
                                            @if ($multiMonth)
                                                Understaffed in {{ $teamLeader['months_understaffed'] }} of {{ $teamLeader['months_in_team'] }} {{ \Illuminate\Support\Str::plural('month', $teamLeader['months_in_team']) }} in the team
                                            @else
                                                {{ $teamLeader['months_understaffed'] > 0 ? 'Yes — fewer than 3 callers' : 'No' }}
                                            @endif
                                        </td>
                                        <td class="text-xs text-gray-600 dark:text-gray-400">{{ $teamLeader['note'] ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="{{ $multiMonth ? 2 : 3 }}" class="{{ $tdClass }} font-semibold">Understaffed-team top-ups</td>
                                    <td class="{{ $tdNumber }} font-bold">{{ $money($target['top_up']) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            @endif
        @else
            <div class="fynn-callout fynn-callout--neutral">
                A {{ $designation }} sits outside the reporting tree and carries no target, so the target is {{ $money($target['total']) }}.
            </div>
        @endif

    {{-- =========================================================== ACHIEVEMENT --}}
    @elseif ($view === 'achievement')
        @php
            $totals = $cases['totals'];
            $emphasis = match ($figure) {
                'gross' => 'actual',
                'cashback', 'subvention', 'docking' => $figure,
                'deductions' => 'deduction',
                default => 'net',
            };
        @endphp

        <div class="fynn-callout">
            <div class="text-sm text-gray-600 dark:text-gray-400">
                Every case disbursed between {{ $period->start->format('d M Y') }} and {{ $period->end->format('d M Y') }} that counts for this employee
                @if ($employee->designation !== Employee::DESIGNATION_CALLER)
                    (their own cases and their team's — each case counted for the team its owner was in on the disbursal date, so a transfer never moves past business)
                @endif
                is added up. For each case:
            </div>
            <div class="mt-3 grid gap-1.5 rounded-lg bg-white/70 p-3 text-sm font-semibold text-gray-950 ring-1 ring-gray-950/5 dark:bg-white/5 dark:text-white dark:ring-white/10">
                <div>Gross = MIS disbursal amount (or the sanctioned amount until the case is settled)</div>
                <div>Deduction = (Cashback + Subvention + Docking) &times; 100, or &times; 50 for BFL Prime / BFL Growth</div>
                <div>Net = Gross &minus; Deduction</div>
            </div>
            <div class="fynn-formula">
                {{ $money($totals['actual']) }} gross &minus; {{ $money($totals['actual'] - $totals['count_achievement']) }} deductions
                = <span class="fynn-formula-result">{{ $money($totals['count_achievement']) }} net</span>
            </div>
            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                Cashback {{ $money($totals['cashback']) }} · Subvention {{ $money($totals['subvention']) }} · Docking {{ $money($totals['docking']) }}
                (BFL Prime · Growth · SOL: {{ $money($totals['cashback_bfl'] + $totals['subvention_bfl'] + $totals['docking_bfl']) }},
                other banks: {{ $money($totals['cashback_other'] + $totals['subvention_other'] + $totals['docking_other']) }})
            </div>
        </div>

        @if (count($cases['members']) > 1 || $employee->designation !== Employee::DESIGNATION_CALLER)
            <div>
                <h4 class="fynn-subheading">By team member</h4>
                <div class="fynn-table-wrap overflow-x-auto">
                    <table class="fynn-table">
                        <thead>
                            <tr>
                                <th class="{{ $thClass }}">Employee</th>
                                <th class="{{ $thClass }} text-end">Cases</th>
                                <th @class([$thClass, 'text-end', $highlight => $emphasis === 'actual'])>Gross</th>
                                <th @class([$thClass, 'text-end', $highlight => in_array($emphasis, ['deduction', 'cashback', 'subvention', 'docking'], true)])>Deduction</th>
                                <th @class([$thClass, 'text-end', $highlight => $emphasis === 'net'])>Net</th>
                                <th class="{{ $thClass }}">Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($cases['members'] as $member)
                                <tr {!! $hideUnlessInactive($member) !!}>
                                    <td class="{{ $tdClass }}">
                                        @include('filament.pages.employee-lookup-explain-person', ['person' => $member])
                                        <div class="text-xs text-gray-500">{{ $member['designation'] }}</div>
                                    </td>
                                    <td class="{{ $tdNumber }}">{{ $member['cases'] }}</td>
                                    <td @class([$tdNumber, $highlight => $emphasis === 'actual'])>{{ $money($member['actual']) }}</td>
                                    <td @class([$tdNumber, $highlight => in_array($emphasis, ['deduction', 'cashback', 'subvention', 'docking'], true)])>{{ $money($member['deduction']) }}</td>
                                    <td @class([$tdNumber, 'font-semibold', $highlight => $emphasis === 'net'])>{{ $money($member['net']) }}</td>
                                    <td class="text-xs text-gray-600 dark:text-gray-400">{{ $member['note'] ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="py-6 text-center text-sm text-gray-500">Nobody in this team in this period.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        <div>
            <h4 class="fynn-subheading">
                Disbursed cases ({{ number_format($cases['count']) }})
                @if ($cases['count'] > count($cases['rows']))
                    <span class="text-xs font-normal text-gray-500">— showing the latest {{ count($cases['rows']) }}; the totals above include all of them</span>
                @endif
            </h4>
            <div class="fynn-table-wrap overflow-x-auto">
                <table class="fynn-table">
                    <thead>
                        <tr>
                            <th class="{{ $thClass }}">Disbursed</th>
                            <th class="{{ $thClass }}">Customer</th>
                            <th class="{{ $thClass }}">Owner</th>
                            <th class="{{ $thClass }}">Bank</th>
                            @foreach (['actual' => 'Gross', 'cashback' => 'Cashback', 'subvention' => 'Subvention', 'docking' => 'Docking'] as $key => $heading)
                                <th @class([$thClass, 'text-end', $highlight => $emphasis === $key])>{{ $heading }}</th>
                            @endforeach
                            <th class="{{ $thClass }} text-end">&times;</th>
                            <th @class([$thClass, 'text-end', $highlight => $emphasis === 'deduction'])>Deduction</th>
                            <th @class([$thClass, 'text-end', $highlight => $emphasis === 'net'])>Net</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($cases['rows'] as $row)
                            <tr {!! $hideUnlessInactive($row) !!}>
                                <td class="{{ $tdClass }} whitespace-nowrap">{{ $row['disbursal_date'] ?? '—' }}</td>
                                <td class="{{ $tdClass }}">
                                    {{ $row['customer_name'] }}
                                    @if ($row['application_no'])
                                        <div class="text-xs text-gray-500">{{ $row['application_no'] }}</div>
                                    @endif
                                </td>
                                <td class="{{ $tdClass }}">
                                    {{ $row['owner'] ?? '—' }}
                                    @if ($row['owner_emp_id'])
                                        <div class="text-xs text-gray-500">{{ $row['owner_emp_id'] }}</div>
                                    @endif
                                </td>
                                <td class="{{ $tdClass }}">{{ $row['bank'] ?: '—' }}</td>
                                @foreach (['actual', 'cashback', 'subvention', 'docking'] as $key)
                                    <td @class([$tdNumber, $highlight => $emphasis === $key])>{{ $money($row[$key]) }}</td>
                                @endforeach
                                <td class="{{ $tdNumber }}">{{ $row['deduction_rate'] ? '×'.$row['deduction_rate'] : '—' }}</td>
                                <td @class([$tdNumber, $highlight => $emphasis === 'deduction'])>{{ $money($row['deduction']) }}</td>
                                <td @class([$tdNumber, 'font-semibold', $highlight => $emphasis === 'net'])>{{ $money($row['net']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="11" class="py-6 text-center text-sm text-gray-500">No case was disbursed for this employee in {{ $period->label }}.</td></tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="4" class="{{ $tdClass }} font-semibold">Total</td>
                            @foreach (['actual', 'cashback', 'subvention', 'docking'] as $key)
                                <td @class([$tdNumber, 'font-bold', $highlight => $emphasis === $key])>{{ $money($totals[$key]) }}</td>
                            @endforeach
                            <td></td>
                            <td @class([$tdNumber, 'font-bold', $highlight => $emphasis === 'deduction'])>{{ $money($totals['actual'] - $totals['count_achievement']) }}</td>
                            <td @class([$tdNumber, 'font-bold', $highlight => $emphasis === 'net'])>{{ $money($totals['count_achievement']) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

    {{-- ============================================================ PERCENTAGE --}}
    @elseif ($view === 'percentage')
        <div class="fynn-callout">
            <div class="text-sm text-gray-600 dark:text-gray-400">Achievement % = Net achievement &divide; Target &times; 100{{ $multiMonth ? ', both for the whole period' : '' }}</div>
            <div class="fynn-formula">
                @if ($summary['target'] > 0)
                    {{ $money($summary['count_achievement']) }} &divide; {{ $money($summary['target']) }} &times; 100
                    = <span class="fynn-formula-result">{{ number_format($summary['percentage'], 2) }}%</span>
                @else
                    No target in {{ $period->label }}, so the percentage is 0%.
                @endif
            </div>
        </div>
        @if ($employee->designation === Employee::DESIGNATION_TEAM_LEADER)
            <p class="text-sm text-gray-700 dark:text-gray-300">
                The incentive gate uses a slightly different percentage — the callers' cases against the callers' targets only, without the understaffed-team top-up. See the incentive breakdown.
            </p>
        @endif
        <div class="flex flex-wrap gap-2">
            <x-filament::button size="sm" color="gray" wire:click="replaceMountedAction('explain', { figure: 'target' })">How the target is calculated</x-filament::button>
            <x-filament::button size="sm" color="gray" wire:click="replaceMountedAction('explain', { figure: 'net' })">How net achievement is added up</x-filament::button>
        </div>

    {{-- ============================================================= REMAINING --}}
    @elseif ($view === 'remaining')
        <div class="fynn-callout">
            <div class="text-sm text-gray-600 dark:text-gray-400">Remaining to target = Target &minus; Net achievement (never below zero)</div>
            <div class="fynn-formula">
                {{ $money($summary['target']) }} &minus; {{ $money($summary['count_achievement']) }} = <span class="fynn-formula-result">{{ $money($summary['remaining']) }}</span>
            </div>
            @if ($summary['target'] > 0 && $summary['count_achievement'] >= $summary['target'])
                <div class="mt-1 text-sm font-semibold text-success-600 dark:text-success-400">
                    Target met — ahead by {{ $money($summary['count_achievement'] - $summary['target']) }}.
                </div>
            @endif
        </div>
        <div class="flex flex-wrap gap-2">
            <x-filament::button size="sm" color="gray" wire:click="replaceMountedAction('explain', { figure: 'target' })">How the target is calculated</x-filament::button>
            <x-filament::button size="sm" color="gray" wire:click="replaceMountedAction('explain', { figure: 'net' })">How net achievement is added up</x-filament::button>
        </div>

    {{-- ============================================================== CATEGORY --}}
    @elseif ($view === 'category')
        <div class="fynn-callout">
            <div class="text-lg font-bold text-gray-950 dark:text-white">{{ $categoryLabel ?? 'No target category assigned' }}</div>
            <div class="mt-1 text-sm text-gray-700 dark:text-gray-300">
                @if ($categoryAmount !== null)
                    Monthly target amount for this category: <span class="font-semibold">{{ $money($categoryAmount) }}</span>
                    (Employee Setup &rarr; Target Categories).
                @else
                    This category has no target amount of its own, so a caller in it carries the default caller target.
                @endif
            </div>
        </div>
        <p class="text-sm text-gray-700 dark:text-gray-300">
            @if ($employee->designation === Employee::DESIGNATION_CALLER)
                For a caller this category amount is their target for each month.
            @else
                For a {{ $designation }} the category is not their target: their target is built from their callers' targets (see the target breakdown).
            @endif
        </p>
        <x-filament::button size="sm" color="gray" wire:click="replaceMountedAction('explain', { figure: 'target' })">How the target is calculated</x-filament::button>

    {{-- ============================================================= INCENTIVE --}}
    @elseif ($view === 'incentive')
        @if ($incentive['kind'] === 'months')
            <div class="fynn-callout">
                <div class="text-sm text-gray-600 dark:text-gray-400">
                    An incentive is earned month by month, so the period's incentive is each month's added up.
                    @switch ($incentive['scheme'])
                        @case ('team_leader')
                            Each month is a Team Leader revenue share, once the team reaches its gate.
                            @break
                        @case ('manager')
                            Each month is the Manager PPP scheme (Managers and Cluster Managers): net achievement &times; the PPP multiplier.
                            @break
                        @default
                            Each month is the slab reached by that month's net achievement.
                    @endswitch
                    Open a month to see it worked out in full.
                </div>
                <div class="fynn-formula">Total incentive {{ $money($incentive['incentive']) }}</div>
            </div>
            @include('filament.pages.employee-lookup-explain-months', ['rows' => $incentive['months'], 'columns' => ['net' => 'Net achievement', 'incentive' => 'Incentive']])
        @elseif ($incentive['kind'] === 'no_data')
            <div class="fynn-callout fynn-callout--neutral">
                Nothing has been disbursed anywhere in {{ $period->label }} yet, so there is no incentive to work out for it.
            </div>
        @elseif ($incentive['kind'] === 'slab')
            <div class="fynn-callout">
                <div class="text-sm text-gray-600 dark:text-gray-400">
                    The incentive is the slab reached by net achievement for the month
                    @if ($employee->designation !== Employee::DESIGNATION_CALLER)
                        (for a {{ $designation }}, the net achievement of their whole team)
                    @endif.
                </div>
                <div class="fynn-formula">
                    Net achievement {{ $money($incentive['net']) }}
                    &rarr;
                    @if ($incentive['reached'] !== null)
                        {{ $money($incentive['reached']) }} slab = {{ $money($incentive['incentive']) }}
                    @else
                        below the first slab = {{ $money(0) }}
                    @endif
                </div>
                @if ($incentive['next'])
                    <div class="mt-1 text-sm text-gray-700 dark:text-gray-300">
                        {{ $money($incentive['next']['remaining']) }} more reaches the {{ $money($incentive['next']['target']) }} slab ({{ $money($incentive['next']['incentive']) }}).
                    </div>
                @endif
            </div>

            <div class="fynn-table-wrap overflow-x-auto">
                <table class="fynn-table">
                    <thead>
                        <tr>
                            <th class="{{ $thClass }}">Net achievement from</th>
                            <th class="{{ $thClass }} text-end">Incentive</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($incentive['slabs'] as $slabFrom => $slabAmount)
                            <tr @class([$highlight.' font-bold' => (float) $slabFrom === $incentive['reached']])>
                                <td class="{{ $tdClass }}">{{ $money($slabFrom) }}</td>
                                <td class="{{ $tdNumber }}">{{ $money($slabAmount) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @elseif ($incentive['kind'] === 'team_leader')
            <div class="fynn-callout">
                <div class="text-sm text-gray-600 dark:text-gray-400">
                    A Team Leader earns a share of their callers' business, once the team reaches its gate.
                    Team figures here are the callers only: callers in the team for at least 10 days, their targets (no top-up) and the cases they disbursed while in the team.
                </div>
                <dl class="fynn-dl mt-3">
                    @foreach ([
                        'Callers' => $incentive['caller_count'].' ('.$incentive['eligible_callers'].' with a target)',
                        'Team target' => $money($incentive['team_target']),
                        'Team net achievement' => $money($incentive['team_achievement']),
                        'Achieved' => number_format($incentive['achievement_percentage'], 2).'% (gate '.number_format($incentive['required_percentage'], 0).'%)',
                    ] as $label => $value)
                        <div class="fynn-dl-item bg-white dark:bg-gray-900">
                            <dt>{{ $label }}</dt>
                            <dd class="tabular-nums">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>

            <ul class="fynn-rules">
                <li>Gate: the team must reach 90% of its target with more than 4 callers, or 100% with 4 or fewer.</li>
                <li>A Team Leader with exactly one caller who has a target earns nothing.</li>
            </ul>

            @if ($incentive['eligible_callers'] === 1)
                <div class="fynn-callout fynn-callout--danger font-semibold text-danger-700 dark:text-danger-400">
                    Only one caller with a target this month, so the incentive is {{ $money(0) }}.
                </div>
            @elseif (! $incentive['meets_gate'])
                <div class="fynn-callout fynn-callout--danger font-semibold text-danger-700 dark:text-danger-400">
                    {{ number_format($incentive['achievement_percentage'], 2) }}% is below the {{ number_format($incentive['required_percentage'], 0) }}% gate, so the incentive is {{ $money(0) }}.
                </div>
            @elseif ($incentive['steps'])
                @php $steps = $incentive['steps']; @endphp
                <div class="fynn-table-wrap overflow-x-auto">
                    <table class="fynn-table">
                        <tbody>
                            <tr><td class="{{ $tdClass }}">1. Revenue = 2% of team net achievement</td><td class="{{ $tdNumber }}">{{ $money($steps['revenue']) }}</td></tr>
                            <tr><td class="{{ $tdClass }}">2. Less caller cost = {{ $incentive['caller_count'] }} callers &times; ₹30,000</td><td class="{{ $tdNumber }}">&minus; {{ $money($steps['caller_cost']) }}</td></tr>
                            <tr><td class="{{ $tdClass }}">3. Gross revenue (not below zero)</td><td class="{{ $tdNumber }}">{{ $money($steps['gross_revenue']) }}</td></tr>
                            <tr><td class="{{ $tdClass }}">4. Gross revenue &times; {{ number_format($steps['gross_rate'] * 100, 0) }}% (6% for 3 callers, 7% for 4, 8% for 5 or more)</td><td class="{{ $tdNumber }}">{{ $money($steps['gross_revenue'] * $steps['gross_rate']) }}</td></tr>
                            <tr><td class="{{ $tdClass }}">5. Extra revenue = Revenue &minus; 2% of team target ({{ $money($steps['target_revenue']) }}), &times; 5%</td><td class="{{ $tdNumber }}">{{ $money($steps['extra_revenue'] * 0.05) }}</td></tr>
                            <tr><td class="{{ $tdClass }}">6. Base incentive = 4 + 5</td><td class="{{ $tdNumber }}">{{ $money($steps['base']) }}</td></tr>
                            <tr>
                                <td class="{{ $tdClass }}">7. Bonus &times; 1.10 when every caller met their own target (3 or more callers)</td>
                                <td class="{{ $tdNumber }}">{{ $steps['bonus'] ? 'Applied' : 'Not applied' }}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td class="{{ $tdClass }} font-bold">Incentive</td>
                                <td class="{{ $tdNumber }} font-bold">{{ $money($incentive['incentive']) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif

            @if ($incentive['callers'] !== [])
                <div>
                    <h4 class="fynn-subheading">Callers in the team's headcount</h4>
                    <div class="fynn-table-wrap overflow-x-auto">
                        <table class="fynn-table">
                            <thead>
                                <tr>
                                    <th class="{{ $thClass }}">Caller</th>
                                    <th class="{{ $thClass }} text-end">Target here</th>
                                    <th class="{{ $thClass }} text-end">Net achieved here</th>
                                    <th class="{{ $thClass }}">Met?</th>
                                    <th class="{{ $thClass }}">Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($incentive['callers'] as $caller)
                                    <tr {!! $hideUnlessInactive($caller) !!}>
                                        <td class="{{ $tdClass }}">@include('filament.pages.employee-lookup-explain-person', ['person' => $caller])</td>
                                        <td class="{{ $tdNumber }}">{{ $money($caller['target']) }}</td>
                                        <td class="{{ $tdNumber }}">{{ $money($caller['achieved']) }}</td>
                                        <td class="{{ $tdClass }}">
                                            <x-filament::badge :color="$caller['met'] ? 'success' : 'danger'" size="sm">{{ $caller['met'] ? 'Yes' : 'No' }}</x-filament::badge>
                                        </td>
                                        <td class="text-xs text-gray-600 dark:text-gray-400">{{ $caller['note'] ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        @elseif ($incentive['kind'] === 'manager')
            <div class="fynn-callout">
                <div class="text-sm text-gray-600 dark:text-gray-400">
                    A Manager's or Cluster Manager's incentive follows PPP (productivity per person): the team's net achievement shared across the callers with a target, matched to a multiplier band.
                </div>
                <div class="fynn-formula space-y-1">
                    <div>
                        PPP = {{ $money($incentive['count_achievement']) }} &divide; {{ $incentive['eligible_callers'] }} callers = {{ $money($incentive['ppp']) }}
                    </div>
                    <div>
                        Incentive = {{ $money($incentive['count_achievement']) }} &times; {{ rtrim(rtrim(number_format($incentive['multiplier'], 5), '0'), '.') ?: '0' }}
                        = <span class="fynn-formula-result">{{ $money($incentive['incentive']) }}</span>
                    </div>
                </div>
            </div>

            <div class="fynn-table-wrap overflow-x-auto">
                <table class="fynn-table">
                    <thead>
                        <tr>
                            <th class="{{ $thClass }}">PPP from</th>
                            <th class="{{ $thClass }} text-end">Multiplier</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr @class([$highlight.' font-bold' => $incentive['multiplier'] == 0])>
                            <td class="{{ $tdClass }}">Below {{ $money($incentive['bands'][0]['from']) }}</td>
                            <td class="{{ $tdNumber }}">0 (no incentive)</td>
                        </tr>
                        @foreach ($incentive['bands'] as $band)
                            <tr @class([$highlight.' font-bold' => $incentive['multiplier'] > 0 && $band['multiplier'] == $incentive['multiplier']])>
                                <td class="{{ $tdClass }}">{{ $money($band['from']) }}</td>
                                <td class="{{ $tdNumber }}">{{ rtrim(rtrim(number_format($band['multiplier'], 5), '0'), '.') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($incentive['callers'] !== [])
                <div>
                    <h4 class="fynn-subheading">Callers in the team's headcount</h4>
                    <div class="fynn-table-wrap overflow-x-auto">
                        <table class="fynn-table">
                            <thead>
                                <tr>
                                    <th class="{{ $thClass }}">Caller</th>
                                    <th class="{{ $thClass }} text-end">Month target</th>
                                    <th class="{{ $thClass }}">Counts in PPP?</th>
                                    <th class="{{ $thClass }}">Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($incentive['callers'] as $caller)
                                    <tr {!! $hideUnlessInactive($caller) !!}>
                                        <td class="{{ $tdClass }}">@include('filament.pages.employee-lookup-explain-person', ['person' => $caller])</td>
                                        <td class="{{ $tdNumber }}">{{ $money($caller['target']) }}</td>
                                        <td class="{{ $tdClass }}">
                                            <x-filament::badge :color="$caller['eligible'] ? 'success' : 'gray'" size="sm">{{ $caller['eligible'] ? 'Yes' : 'No — no target' }}</x-filament::badge>
                                        </td>
                                        <td class="text-xs text-gray-600 dark:text-gray-400">{{ $caller['note'] ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        @endif
    @endif
</div>
