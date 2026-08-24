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
