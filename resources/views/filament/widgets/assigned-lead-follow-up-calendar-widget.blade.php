@php
    $plugin = \Saade\FilamentFullCalendar\FilamentFullCalendarPlugin::get();
@endphp

<x-filament-widgets::widget>
    <x-filament::section>
        <div class="flex justify-end flex-1 mb-4">
            <x-filament::actions :actions="$this->getCachedHeaderActions()" class="shrink-0" />
        </div>

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
                            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Follow-Up Calendar</h3>
                        </div>
                        <span class="lead-followup-calendar-card__legend">
                            <span class="lead-followup-calendar-card__legend-dot"></span>
                            Has follow-ups
                        </span>
                    </div>

                    <div class="p-3">
                        <div wire:ignore x-load
                            x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('filament-fullcalendar-alpine', 'saade/filament-fullcalendar') }}"
                            x-ignore x-data="fullcalendar({
                                locale: @js($plugin->getLocale()),
                                plugins: @js($plugin->getPlugins()),
                                schedulerLicenseKey: @js($plugin->getSchedulerLicenseKey()),
                                timeZone: @js($plugin->getTimezone()),
                                config: @js($this->getConfig()),
                                editable: @json($plugin->isEditable()),
                                selectable: @json($plugin->isSelectable()),
                                eventClassNames: {!! htmlspecialchars($this->eventClassNames(), ENT_COMPAT) !!},
                                eventContent: {!! htmlspecialchars($this->eventContent(), ENT_COMPAT) !!},
                                eventDidMount: {!! htmlspecialchars($this->eventDidMount(), ENT_COMPAT) !!},
                                eventWillUnmount: {!! htmlspecialchars($this->eventWillUnmount(), ENT_COMPAT) !!},
                            })" class="filament-fullcalendar"></div>
                    </div>
                </div>
            </div>

            <div class="min-w-0 lg:w-1/3">
                <div class="rounded-lg border border-gray-200 dark:border-gray-700">
                    <div class="border-b border-gray-200 px-4 py-3 dark:border-gray-700">
                        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">
                            @if ($selectedDate)
                                Follow-ups on {{ \Carbon\Carbon::parse($selectedDate)->format('d M Y') }}
                            @else
                                Follow-ups
                            @endif
                        </h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            Click any date on the calendar to see that day's follow-ups here.
                        </p>
                    </div>

                    <div class="max-h-[40rem] overflow-y-auto p-4">
                        @if ($selectedDate)
                            @include('filament.widgets.day-follow-ups-list', [
                                'followUps' => $this->followUpsForDate($selectedDate),
                            ])
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
