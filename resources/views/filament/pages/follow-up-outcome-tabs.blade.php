{{-- The outcome tabs of FollowUpOutcomeListPage as compact colour-coded pills, centred in the table toolbar (TOOLBAR_START hook, theme.css); each count follows the dates, owner and table filters. --}}
@php
    $palette = [
        'success' => [
            'idle' => 'border-emerald-200 bg-emerald-50 text-emerald-700 hover:bg-emerald-100 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-400',
            'idleCount' => 'bg-emerald-600 text-white',
            'active' => 'border-emerald-800 bg-emerald-600 text-white ring-2 ring-emerald-300 ring-offset-1 dark:ring-emerald-500/50 dark:ring-offset-gray-900',
            'activeCount' => 'bg-white text-emerald-700',
        ],
        'warning' => [
            'idle' => 'border-amber-200 bg-amber-50 text-amber-700 hover:bg-amber-100 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-400',
            'idleCount' => 'bg-amber-500 text-white',
            'active' => 'border-amber-700 bg-amber-500 text-white ring-2 ring-amber-300 ring-offset-1 dark:ring-amber-500/50 dark:ring-offset-gray-900',
            'activeCount' => 'bg-white text-amber-700',
        ],
        'danger' => [
            'idle' => 'border-rose-200 bg-rose-50 text-rose-700 hover:bg-rose-100 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-400',
            'idleCount' => 'bg-rose-600 text-white',
            'active' => 'border-rose-800 bg-rose-600 text-white ring-2 ring-rose-300 ring-offset-1 dark:ring-rose-500/50 dark:ring-offset-gray-900',
            'activeCount' => 'bg-white text-rose-700',
        ],
        'info' => [
            'idle' => 'border-sky-200 bg-sky-50 text-sky-700 hover:bg-sky-100 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-400',
            'idleCount' => 'bg-sky-600 text-white',
            'active' => 'border-sky-800 bg-sky-600 text-white ring-2 ring-sky-300 ring-offset-1 dark:ring-sky-500/50 dark:ring-offset-gray-900',
            'activeCount' => 'bg-white text-sky-700',
        ],
    ];
@endphp

<div class="fynn-outcome-tabs">
    <div
        role="tablist"
        aria-label="Follow-up outcome"
        class="flex flex-wrap items-center justify-center gap-2"
    >
        @foreach ($livewire->outcomeTabs() as $key => $tab)
            @php
                $isActive = (string) $livewire->activeTab === $key;
                $colors = $palette[$tab['color']] ?? $palette['info'];
            @endphp

            <button
                type="button"
                role="tab"
                title="{{ $tab['hint'] }}"
                aria-selected="{{ $isActive ? 'true' : 'false' }}"
                wire:key="outcome-tab-{{ $key }}"
                wire:click="$set('activeTab', '{{ $key }}')"
                wire:loading.attr="disabled"
                @class([
                    'inline-flex items-center gap-2 rounded-lg border px-3 py-1.5 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500',
                    $colors['active'] => $isActive,
                    $colors['idle'] => ! $isActive,
                ])
            >
                <span>{{ $tab['label'] }}</span>

                <span @class([
                    'min-w-6 rounded-md px-1.5 py-0.5 text-center text-xs font-bold tabular-nums',
                    $colors['activeCount'] => $isActive,
                    $colors['idleCount'] => ! $isActive,
                ])>{{ number_format($tab['count']) }}</span>
            </button>
        @endforeach
    </div>
</div>
