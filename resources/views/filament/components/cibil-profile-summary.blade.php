@php
    /**
     * Rendered inside the table description (a <p>), so only inline elements.
     *
     * @var string $type
     * @var string|null $name
     * @var string $pan
     * @var string|null $mobile
     * @var \App\Models\CibilReport|null $latestSuccess
     * @var \App\Models\CibilReport|null $latestRequest
     * @var \App\Models\CibilReport|null $latestPdf
     * @var string|null $pdfUrl
     */
    $score = $latestSuccess?->score;
@endphp

<span class="mt-2 flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
    <span>
        <span class="text-gray-500 dark:text-gray-400">{{ $type }}:</span>
        <span class="font-medium text-gray-950 dark:text-white">{{ $name ?: '—' }}</span>
    </span>

    <span>
        <span class="text-gray-500 dark:text-gray-400">PAN:</span>
        <span class="font-mono text-gray-950 dark:text-white">{{ $pan }}</span>
    </span>

    <span>
        <span class="text-gray-500 dark:text-gray-400">Mobile:</span>
        <span class="font-mono text-gray-950 dark:text-white">{{ $mobile ?? '—' }}</span>
    </span>

    <span class="inline-flex items-center gap-1">
        <span class="text-gray-500 dark:text-gray-400">Latest score:</span>
        @if ($latestSuccess === null)
            <span class="text-gray-950 dark:text-white">Not checked yet</span>
        @elseif ($score === null)
            <span class="text-gray-950 dark:text-white">No score returned</span>
        @else
            <x-filament::badge :color="$latestSuccess->scoreBand()?->color() ?? 'gray'">
                {{ $score < 300 ? 'NH (no history)' : $score }}
            </x-filament::badge>
            <span class="text-gray-500 dark:text-gray-400">{{ $latestSuccess->bureau->label() }}</span>
        @endif
    </span>

    <span>
        <span class="text-gray-500 dark:text-gray-400">Last successful report:</span>
        <span class="text-gray-950 dark:text-white">
            {{ $latestSuccess ? $latestSuccess->bureau->label().' '.$latestSuccess->request_type->label().' · '.$latestSuccess->fetched_at?->format('d M Y, h:i A') : '—' }}
        </span>
    </span>

    <span class="inline-flex items-center gap-1">
        <span class="text-gray-500 dark:text-gray-400">Latest request:</span>
        @if ($latestRequest)
            <x-filament::badge :color="$latestRequest->status->color()">
                {{ $latestRequest->bureau->label() }} {{ $latestRequest->request_type->label() }} · {{ $latestRequest->status->label() }}
            </x-filament::badge>
        @else
            <span class="text-gray-950 dark:text-white">—</span>
        @endif
    </span>

    <span class="inline-flex items-center gap-1">
        <span class="text-gray-500 dark:text-gray-400">PDF:</span>
        @if ($pdfUrl)
            <x-filament::link :href="$pdfUrl" target="_blank" icon="heroicon-m-document-arrow-down">
                Open
            </x-filament::link>
        @elseif ($latestPdf?->pdf_status)
            <x-filament::badge :color="$latestPdf->pdf_status->color()">
                {{ $latestPdf->pdf_status->label() }}
            </x-filament::badge>
        @else
            <span class="text-gray-950 dark:text-white">None</span>
        @endif
    </span>
</span>
