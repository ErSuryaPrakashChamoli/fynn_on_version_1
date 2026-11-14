<x-filament-panels::page>
    <p class="fynn-callout fynn-callout--info flex items-start gap-3">
        <x-filament::icon icon="heroicon-m-cursor-arrow-rays" class="mt-0.5 size-5 shrink-0 text-info-600 dark:text-info-400" />
        <span>Follow-ups for your assigned leads. Click any day to schedule a follow-up, or click an existing event to view or update it.</span>
    </p>

    @livewire(\App\Filament\Widgets\AssignedLeadFollowUpCalendarWidget::class)
</x-filament-panels::page>
