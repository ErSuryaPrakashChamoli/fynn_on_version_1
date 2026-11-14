<x-filament-panels::page>
    <p class="fynn-callout fynn-callout--info flex items-start gap-3">
        <x-filament::icon icon="heroicon-m-cursor-arrow-rays" class="mt-0.5 size-5 shrink-0 text-info-600 dark:text-info-400" />
        <span>Your customer follow-ups, on a calendar. Click any day to see that day's follow-ups, or click a follow-up to open its customer record.</span>
    </p>

    @livewire(\App\Filament\Widgets\CustomerFollowUpCalendarWidget::class)
</x-filament-panels::page>
