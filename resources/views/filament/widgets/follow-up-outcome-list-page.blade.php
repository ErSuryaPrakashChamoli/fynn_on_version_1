{{-- One source's cards in the outcome list; $list comes from ShowsFollowUpOutcomes::outcomeList(). --}}
<div class="space-y-3">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <p class="text-xs text-gray-500 dark:text-gray-400">
            @if ($list['total'] > 0)
                Showing {{ number_format($list['followUps']->count()) }} of {{ number_format($list['total']) }}
            @endif
        </p>

        @if ($list['listing'])
            <x-filament::link
                size="sm"
                icon="heroicon-m-arrow-top-right-on-square"
                :href="$list['listing']::getUrl([
                    'activeTab' => $outcomeListFilter,
                    'filters' => ['from' => $outcomeListStart->toDateString(), 'to' => $outcomeListEnd->toDateString()],
                ])"
            >
                Open full listing
            </x-filament::link>
        @endif
    </div>

    @include('filament.widgets.day-follow-ups-list', [
        'followUps' => $list['followUps'],
        'showDueDate' => true,
        'emptyMessage' => 'No follow-ups match these dates.',
    ])

    @if ($list['followUps']->count() < $list['total'])
        <div class="flex justify-center">
            <x-filament::button color="gray" size="sm" wire:click="showMoreOutcomeList" wire:loading.attr="disabled" wire:target="showMoreOutcomeList">
                Show more
            </x-filament::button>
        </div>
    @endif
</div>
