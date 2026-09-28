@php
    $leadFollowUps = $selectedDate ? $this->leadFollowUpsForDate($selectedDate) : collect();
    $customerFollowUps = $selectedDate ? $this->customerFollowUpsForDate($selectedDate) : collect();
    $grid = $this->calendarGrid();
    $views = ['month' => 'Month', 'week' => 'Week', 'day' => 'Day'];
@endphp

<x-filament-widgets::widget>
    {{-- The section class and the header action's placement inside the
         calendar card are what keep the calendar inside the first screen
         of the Dashboard; see "Compact page chrome" in theme.css. --}}
    <x-filament::section class="fynn-dashboard-calendar-section">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start">
            <div class="min-w-0 lg:w-2/3">
                <div class="lead-followup-calendar-card rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
                    <div class="lead-followup-calendar-card__header flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 px-4 py-3 dark:border-gray-700">
                        <div class="flex items-center gap-2">
                            <span class="lead-followup-calendar-card__icon">
                                <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M5.75 2a.75.75 0 01.75.75V4h7V2.75a.75.75 0 011.5 0V4h.25A2.75 2.75 0 0118 6.75v8.5A2.75 2.75 0 0115.25 18H4.75A2.75 2.75 0 012 15.25v-8.5A2.75 2.75 0 014.75 4H5V2.75A.75.75 0 015.75 2zM3.5 8.5v6.75c0 .69.56 1.25 1.25 1.25h10.5c.69 0 1.25-.56 1.25-1.25V8.5h-13z" clip-rule="evenodd" />
                                </svg>
                            </span>
                            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Follow-Ups</h3>
                        </div>
                        <div class="flex flex-wrap items-center gap-3">
                            @include('filament.widgets.follow-up-outcome-legend')
                            <x-filament::actions :actions="$this->getCachedHeaderActions()" class="shrink-0" />
                        </div>
                    </div>

                    {{-- The grid is drawn here on the server — not by the FullCalendar
                         script — so it and its day chips arrive with the page in one
                         go (DashboardFollowUpCalendarWidget::calendarGrid()). It keeps
                         FullCalendar's class names, so the theme's calendar styling
                         applies unchanged. fitToScreen() sizes the week rows so the
                         whole grid ends inside the first screen; the rows read
                         --fynn-calendar-row-h in theme.css. --}}
                    <div class="p-3"
                        x-data="{
                            fitFrame: null,
                            fitToScreen() {
                                const calendar = this.$el.querySelector('.filament-fullcalendar');
                                const body = calendar?.querySelector('.fc-daygrid-body');
                                const rows = body ? body.querySelectorAll('tbody > tr').length : 0;

                                if (! rows) {
                                    return;
                                }

                                const card = this.$el.closest('.lead-followup-calendar-card') ?? this.$el;
                                const region = document.querySelector('.fi-main-ctn');
                                // Positions are taken as if the page were scrolled to the top.
                                const restTop = () => region ? region.getBoundingClientRect().top - region.scrollTop : -window.scrollY;
                                const visibleHeight = (region ? region.clientHeight : window.innerHeight) - 12;
                                const minRow = 44;
                                const apply = (height) => calendar.style.setProperty('--fynn-calendar-row-h', `${height}px`);

                                // First pass: split the room below the grid's top across the week rows.
                                const bodyRect = body.getBoundingClientRect();
                                const belowBody = card.getBoundingClientRect().bottom - bodyRect.bottom;
                                const rowHeight = Math.max(minRow, Math.floor((visibleHeight - (bodyRect.top - restTop()) - belowBody) / rows));
                                apply(rowHeight);

                                // Second pass: whatever the cells' own spacing added, take it back off the rows.
                                const overflow = (card.getBoundingClientRect().bottom - restTop()) - visibleHeight;

                                if (overflow > 0) {
                                    apply(Math.max(minRow, rowHeight - Math.ceil(overflow / rows)));
                                }
                            },
                            scheduleFit() {
                                if (this.fitFrame === null) {
                                    this.fitFrame = requestAnimationFrame(() => {
                                        this.fitFrame = null;
                                        this.fitToScreen();
                                    });
                                }
                            },
                            // Mark the clicked day at once, in the browser; the side panel
                            // follows on the server round trip. The cell is passed in: from a
                            // child's handler this.$el is that child, not this wrapper.
                            pickDay(cell) {
                                cell.closest('.fc-daygrid-body')?.querySelectorAll('.is-selected-day').forEach((selected) => selected.classList.remove('is-selected-day'));
                                cell.classList.add('is-selected-day');
                                $wire.selectCalendarDate(cell.dataset.date);
                            },
                        }"
                        x-init="
                            fitToScreen();
                            scheduleFit();
                            new MutationObserver(() => scheduleFit()).observe($el, { childList: true, subtree: true });
                        "
                        x-on:resize.window="scheduleFit()">
                        {{-- wire:ignore.self: a re-render (picking a day, changing month)
                             must not wipe the --fynn-calendar-row-h that fitToScreen() set
                             on this element; its contents still update. --}}
                        <div class="filament-fullcalendar fynn-server-calendar" wire:ignore.self wire:loading.class="fynn-server-calendar--busy" wire:target="showPreviousPeriod,showNextPeriod,showTodayPeriod,switchCalendarView">
                            <div class="fc-header-toolbar fc-toolbar">
                                <div class="fc-toolbar-chunk">
                                    <div class="fc-button-group" role="group" aria-label="Calendar view">
                                        @foreach ($views as $view => $label)
                                            <button type="button"
                                                wire:click="switchCalendarView('{{ $view }}')"
                                                @class(['fc-button fc-button-primary', 'fc-button-active' => $calendarView === $view])
                                                aria-pressed="{{ $calendarView === $view ? 'true' : 'false' }}">{{ $label }}</button>
                                        @endforeach
                                    </div>
                                </div>

                                <div class="fc-toolbar-chunk">
                                    <h2 class="fc-toolbar-title">{{ $this->calendarTitle() }}</h2>
                                </div>

                                <div class="fc-toolbar-chunk">
                                    <div class="fc-button-group" role="group" aria-label="Move calendar">
                                        <button type="button" wire:click="showPreviousPeriod" class="fc-button fc-button-primary" title="Previous" aria-label="Previous">
                                            <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M12.79 5.23a.75.75 0 01-.02 1.06L8.832 10l3.938 3.71a.75.75 0 11-1.04 1.08l-4.5-4.25a.75.75 0 010-1.08l4.5-4.25a.75.75 0 011.06.02z" clip-rule="evenodd" /></svg>
                                        </button>
                                        <button type="button" wire:click="showNextPeriod" class="fc-button fc-button-primary" title="Next" aria-label="Next">
                                            <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" /></svg>
                                        </button>
                                    </div>
                                    <button type="button" wire:click="showTodayPeriod" class="fc-button fc-button-primary fc-today-button" @disabled($this->isTodayOnScreen())>Today</button>
                                </div>
                            </div>

                            <div class="fc-view">
                                <table class="fynn-server-calendar__table">
                                    <thead>
                                        <tr>
                                            @foreach ($grid['columns'] as $column)
                                                <th class="fc-col-header-cell"><span class="fc-col-header-cell-cushion">{{ $column }}</span></th>
                                            @endforeach
                                        </tr>
                                    </thead>
                                </table>
                                <table class="fynn-server-calendar__table fc-daygrid-body">
                                    <tbody>
                                        @foreach ($grid['weeks'] as $week)
                                            <tr>
                                                @foreach ($week as $cell)
                                                    @php
                                                        $event = $cell['event'];
                                                        $props = $event['extendedProps'] ?? [];
                                                    @endphp
                                                    @if (! $cell['inPeriod'])
                                                        <td class="fc-daygrid-day fc-day-disabled"><div class="fc-daygrid-day-frame"></div></td>
                                                    @else
                                                        <td wire:key="calendar-day-{{ $cell['date'] }}"
                                                            data-date="{{ $cell['date'] }}"
                                                            x-on:click="pickDay($el)"
                                                            @class([
                                                                'fc-daygrid-day',
                                                                'fc-day-today' => $cell['isToday'],
                                                                'fc-day-sat' => \Carbon\Carbon::parse($cell['date'])->isSaturday(),
                                                                'fc-day-sun' => \Carbon\Carbon::parse($cell['date'])->isSunday(),
                                                                'has-followups' => $event !== null,
                                                                'has-missed-followups' => ($props['missed'] ?? 0) > 0,
                                                                'is-selected-day' => $cell['date'] === $selectedDate,
                                                            ])>
                                                            <div class="fc-daygrid-day-frame">
                                                                <div class="fc-daygrid-day-top">
                                                                    <span class="fc-daygrid-day-number">{{ $cell['day'] }}</span>
                                                                </div>
                                                                @if ($event)
                                                                    <div class="fc-daygrid-day-events">
                                                                        <button type="button" class="fc-daygrid-event lead-followup-day-chip" title="{{ $event['title'] }}">
                                                                            <div class="followup-outcome-chip">
                                                                                @if ($props['onTime'] ?? 0)
                                                                                    <span class="followup-outcome followup-outcome--on-time" title="Kept on time">✓ {{ $props['onTime'] }}</span>
                                                                                @endif
                                                                                @if ($props['late'] ?? 0)
                                                                                    <span class="followup-outcome followup-outcome--late" title="Kept late">⏱ {{ $props['late'] }}</span>
                                                                                @endif
                                                                                @if ($props['missed'] ?? 0)
                                                                                    <span class="followup-outcome followup-outcome--missed" title="Missed">✗ {{ $props['missed'] }}</span>
                                                                                @endif
                                                                                @if ($props['open'] ?? 0)
                                                                                    <span class="followup-outcome followup-outcome--open" title="Still open">{{ $props['open'] }} open</span>
                                                                                @endif
                                                                                @if ($props['overloaded'] ?? 0)
                                                                                    <span class="followup-outcome followup-outcome--overloaded" title="{{ $props['overloaded'] }} caller(s) booked past {{ $props['capacity'] }} a day">⚠ {{ $props['overloaded'] }}</span>
                                                                                @endif
                                                                            </div>
                                                                        </button>
                                                                    </div>
                                                                @endif
                                                            </div>
                                                        </td>
                                                    @endif
                                                @endforeach
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- The day panel is split into Lead / Customer tabs, switched in the
                 browser (no round trip). The picked tab survives re-renders, so
                 clicking another date keeps showing the same kind. --}}
            <div class="min-w-0 lg:w-1/3">
                <div class="rounded-lg border border-gray-200 dark:border-gray-700" x-data="{ followUpTab: 'lead' }">
                    <div class="flex border-b border-gray-200 dark:border-gray-700" role="tablist" aria-label="Follow-up type">
                        <button type="button" role="tab"
                            x-on:click="followUpTab = 'lead'"
                            x-bind:aria-selected="followUpTab === 'lead'"
                            x-bind:class="followUpTab === 'lead'
                                ? 'border-primary-600 text-primary-700 dark:border-primary-400 dark:text-primary-400'
                                : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'"
                            class="flex flex-1 items-center justify-center gap-2 border-b-2 px-3 py-2.5 text-sm font-semibold transition">
                            <span>Lead Follow-ups</span>
                            <span class="rounded-full bg-primary-50 px-2 py-0.5 text-xs text-primary-700 dark:bg-primary-500/10 dark:text-primary-400">{{ $leadFollowUps->count() }}</span>
                        </button>
                        <button type="button" role="tab"
                            x-on:click="followUpTab = 'customer'"
                            x-bind:aria-selected="followUpTab === 'customer'"
                            x-bind:class="followUpTab === 'customer'
                                ? 'border-teal-600 text-teal-700 dark:border-teal-400 dark:text-teal-400'
                                : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'"
                            class="flex flex-1 items-center justify-center gap-2 border-b-2 px-3 py-2.5 text-sm font-semibold transition">
                            <span>Customer Follow-ups</span>
                            <span class="rounded-full bg-teal-50 px-2 py-0.5 text-xs text-teal-700 dark:bg-teal-500/10 dark:text-teal-400">{{ $customerFollowUps->count() }}</span>
                        </button>
                    </div>

                    <div class="border-b border-gray-200 px-4 py-3 dark:border-gray-700">
                        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">
                            @if ($selectedDate)
                                <span x-text="followUpTab === 'lead' ? 'Lead follow-ups' : 'Customer follow-ups'">Lead follow-ups</span>
                                on {{ \Carbon\Carbon::parse($selectedDate)->format('d M Y') }}
                            @else
                                Follow-ups
                            @endif
                        </h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            Click any date on the calendar to see that day's follow-ups here.
                        </p>

                        @include('filament.widgets.missed-follow-up-actions')
                    </div>

                    <div class="max-h-[44rem] overflow-y-auto p-4">
                        @if ($selectedDate)
                            <div role="tabpanel" x-show="followUpTab === 'lead'" wire:key="day-panel-lead">
                                @include('filament.widgets.day-follow-ups-list', ['followUps' => $leadFollowUps])
                            </div>

                            <div role="tabpanel" x-show="followUpTab === 'customer'" x-cloak wire:key="day-panel-customer">
                                @include('filament.widgets.day-follow-ups-list', ['followUps' => $customerFollowUps])
                            </div>
                        @else
                            <div class="rounded-lg border border-dashed border-gray-300 p-6 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
                                No date selected yet.
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </x-filament::section>

    <x-filament-actions::modals />
</x-filament-widgets::widget>
