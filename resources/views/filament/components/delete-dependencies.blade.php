@php
    /** @var list<array{label: string, url: string|null}> $dependents */
    /** @var int $total */
    /** @var string $noun */
    $remaining = $total - count($dependents);
@endphp

<div class="rounded-xl border border-gray-200 dark:border-white/10">
    <div class="border-b border-gray-200 px-4 py-2 text-xs font-bold uppercase tracking-wide text-gray-600 dark:border-white/10 dark:text-gray-300">
        {{ \Illuminate\Support\Str::plural(\Illuminate\Support\Str::ucfirst($noun)) }} holding it
    </div>

    <ul class="max-h-60 divide-y divide-gray-200 overflow-y-auto dark:divide-white/10">
        @foreach ($dependents as $dependent)
            <li class="flex items-center justify-between gap-3 px-4 py-2 text-sm">
                <span class="text-gray-950 dark:text-white">{{ $dependent['label'] }}</span>

                @if ($dependent['url'])
                    <a
                        href="{{ $dependent['url'] }}"
                        target="_blank"
                        class="shrink-0 text-xs font-semibold text-primary-600 hover:underline dark:text-primary-400"
                    >
                        Edit
                    </a>
                @endif
            </li>
        @endforeach
    </ul>

    @if ($remaining > 0)
        <p class="border-t border-gray-200 px-4 py-2 text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
            …and {{ $remaining }} more {{ \Illuminate\Support\Str::plural($noun, $remaining) }}.
        </p>
    @endif
</div>
