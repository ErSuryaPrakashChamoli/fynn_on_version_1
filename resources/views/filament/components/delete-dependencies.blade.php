@php
    /** @var list<array{label: string, url: string|null}> $dependents */
    /** @var int $total */
    /** @var string $noun */
    /** @var string|null $heading */
    /** @var string|null $linkLabel */
    $remaining = $total - count($dependents);
    $heading ??= \Illuminate\Support\Str::plural(\Illuminate\Support\Str::ucfirst($noun)).' holding it';
    $linkLabel ??= 'Edit';
@endphp

<div class="fynn-card fynn-card--flush">
    <div class="fynn-subheading mb-0 border-b border-gray-950/5 bg-gray-50 px-4 py-2.5 dark:border-white/5 dark:bg-white/5">
        {{ $heading }}
    </div>

    <ul class="fynn-list max-h-60 overflow-y-auto px-2 py-1">
        @foreach ($dependents as $dependent)
            <li class="fynn-list-row">
                <span class="fynn-list-title min-w-0">{{ $dependent['label'] }}</span>

                @if ($dependent['url'])
                    <a
                        href="{{ $dependent['url'] }}"
                        target="_blank"
                        class="inline-flex shrink-0 items-center gap-1 text-xs font-semibold text-primary-700 hover:underline dark:text-primary-300"
                    >
                        {{ $linkLabel }}
                        <x-filament::icon icon="heroicon-m-arrow-top-right-on-square" :size="\Filament\Support\Enums\IconSize::Small" />
                    </a>
                @endif
            </li>
        @endforeach
    </ul>

    @if ($remaining > 0)
        <p class="border-t border-gray-950/5 px-4 py-2 text-xs text-gray-500 dark:border-white/5 dark:text-gray-400">
            …and {{ $remaining }} more {{ \Illuminate\Support\Str::plural($noun, $remaining) }}.
        </p>
    @endif
</div>
