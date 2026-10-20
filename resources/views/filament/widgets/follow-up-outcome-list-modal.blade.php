{{-- The outcome list behind the legend chips (ShowsFollowUpOutcomes):
     every follow-up with the picked verdict due in the chosen dates, split
     into the widget's sources (Lead / Customer on the Dashboard). Closing
     the modal clears the list, so the calendar stops building it. --}}
@php
    $outcomeListModalId = $this->outcomeListModalId();
    $outcomeFilters = $this::outcomeListFilters();
    $activeOutcomeFilter = $outcomeFilters[$outcomeListFilter ?? ''] ?? null;
    $outcomeList = $this->outcomeList();
    [$outcomeListStart, $outcomeListEnd] = $this->outcomeListPeriod();
    $outcomeChipClasses = ['on_time' => 'on-time', 'late' => 'late', 'missed' => 'missed', 'open' => 'open'];
    $outcomeListLoading = 'openOutcomeList,outcomeListFrom,outcomeListTo,showMoreOutcomeList';
@endphp

<div x-on:modal-closed.window="if ($event.detail.id === @js($outcomeListModalId)) $wire.closeOutcomeList()">
    <x-filament::modal
        :id="$outcomeListModalId"
        slide-over
        width="2xl"
        :heading="$activeOutcomeFilter ? $activeOutcomeFilter['label'].' follow-ups' : 'Follow-ups'"
        :description="'Due '.$outcomeListStart->format('d M Y').' – '.$outcomeListEnd->format('d M Y')"
    >
        <div class="space-y-4">
            <div class="followup-outcome-legend followup-outcome-legend--start" role="group" aria-label="Outcome">
                @foreach ($outcomeFilters as $filter => $definition)
                    <button type="button"
                        wire:click="openOutcomeList(@js($filter))"
                        aria-pressed="{{ $outcomeListFilter === $filter ? 'true' : 'false' }}"
                        @class([
                            'followup-outcome followup-outcome--clickable',
                            'followup-outcome--'.$outcomeChipClasses[$filter],
                            'followup-outcome--active' => $outcomeListFilter === $filter,
                        ])>{{ $definition['label'] }}</button>
                @endforeach
            </div>

            <div class="grid grid-cols-2 gap-3">
                <label class="block space-y-1">
                    <span class="text-xs font-medium text-gray-700 dark:text-gray-300">Due from</span>
                    <x-filament::input.wrapper>
                        <x-filament::input type="date" wire:model.live.debounce.500ms="outcomeListFrom" />
                    </x-filament::input.wrapper>
                </label>
                <label class="block space-y-1">
                    <span class="text-xs font-medium text-gray-700 dark:text-gray-300">Due to</span>
                    <x-filament::input.wrapper>
                        <x-filament::input type="date" wire:model.live.debounce.500ms="outcomeListTo" :min="$outcomeListFrom" />
                    </x-filament::input.wrapper>
                </label>
            </div>

            <div wire:loading.flex wire:target="{{ $outcomeListLoading }}" class="items-center justify-center gap-2 py-6 text-sm text-gray-500 dark:text-gray-400">
                <x-filament::loading-indicator class="h-5 w-5" />
                Loading follow-ups…
            </div>

            <div wire:loading.remove wire:target="openOutcomeList">
                @if (count($outcomeList) > 1)
                    <div x-data="{ outcomeTab: @js(array_key_first($outcomeList)) }">
                        <div class="flex border-b border-gray-200 dark:border-gray-700" role="tablist" aria-label="Follow-up type">
                            @foreach ($outcomeList as $source => $list)
                                <button type="button" role="tab"
                                    x-on:click="outcomeTab = @js($source)"
                                    x-bind:aria-selected="outcomeTab === @js($source)"
                                    x-bind:class="outcomeTab === @js($source)
                                        ? 'border-primary-600 text-primary-700 dark:border-primary-400 dark:text-primary-400'
                                        : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'"
                                    class="flex flex-1 items-center justify-center gap-2 border-b-2 px-3 py-2.5 text-sm font-semibold transition">
                                    <span>{{ $list['label'] }}</span>
                                    <span class="rounded-full bg-primary-50 px-2 py-0.5 text-xs text-primary-700 dark:bg-primary-500/10 dark:text-primary-400">{{ number_format($list['total']) }}</span>
                                </button>
                            @endforeach
                        </div>

                        @foreach ($outcomeList as $source => $list)
                            <div role="tabpanel" class="pt-4" x-show="outcomeTab === @js($source)" @if (! $loop->first) x-cloak @endif wire:key="outcome-list-{{ $source }}">
                                @include('filament.widgets.follow-up-outcome-list-page', ['list' => $list])
                            </div>
                        @endforeach
                    </div>
                @else
                    @foreach ($outcomeList as $source => $list)
                        <div wire:key="outcome-list-{{ $source }}">
                            @include('filament.widgets.follow-up-outcome-list-page', ['list' => $list])
                        </div>
                    @endforeach
                @endif
            </div>
        </div>
    </x-filament::modal>
</div>
