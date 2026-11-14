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
     * @var array<string, \App\Models\CibilReport|null> $latestByBureau
     * @var int $requestCount
     */
@endphp

{{-- Label / value tiles (.fynn-dl look) built from spans, since this sits inside a <p>. --}}
<span class="fynn-dl mt-3 text-sm">
    <span class="fynn-dl-item">
        <span class="fynn-kpi-label block">{{ $type }}:</span>
        <span class="mt-0.5 block font-semibold text-gray-950 dark:text-white">{{ $name ?: '—' }}</span>
    </span>

    <span class="fynn-dl-item">
        <span class="fynn-kpi-label block">PAN:</span>
        <span class="mt-0.5 block font-mono font-semibold text-gray-950 dark:text-white">{{ $pan }}</span>
    </span>

    <span class="fynn-dl-item">
        <span class="fynn-kpi-label block">Mobile:</span>
        <span class="mt-0.5 block font-mono font-semibold text-gray-950 dark:text-white">{{ $mobile ?? '—' }}</span>
    </span>

    @foreach ($latestByBureau as $bureauLabel => $bureauReport)
        <span class="fynn-dl-item">
            <span class="fynn-kpi-label block">{{ $bureauLabel }} score:</span>
            <span class="mt-0.5 flex flex-wrap items-center gap-1.5">
                @if ($bureauReport === null)
                    <span class="font-semibold text-gray-950 dark:text-white">Not checked yet</span>
                @elseif ($bureauReport->score === null)
                    <span class="font-semibold text-gray-950 dark:text-white">No score returned</span>
                @else
                    <x-filament::badge :color="$bureauReport->scoreBand()?->color() ?? 'gray'">
                        {{ $bureauReport->score < 300 ? 'NH (no history)' : $bureauReport->score }}
                    </x-filament::badge>
                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ $bureauReport->fetched_at?->format('d M Y') }}</span>
                @endif
            </span>
        </span>
    @endforeach

    <span class="fynn-dl-item">
        <span class="fynn-kpi-label block">Requests on this file:</span>
        <span class="mt-0.5 block font-semibold tabular-nums text-gray-950 dark:text-white">{{ $requestCount }}</span>
    </span>

    <span class="fynn-dl-item">
        <span class="fynn-kpi-label block">Last successful report:</span>
        <span class="mt-0.5 block font-semibold text-gray-950 dark:text-white">
            {{ $latestSuccess ? $latestSuccess->bureau->label().' '.$latestSuccess->request_type->label().' · '.$latestSuccess->fetched_at?->format('d M Y, h:i A') : '—' }}
        </span>
    </span>

    <span class="fynn-dl-item">
        <span class="fynn-kpi-label block">Latest request:</span>
        <span class="mt-0.5 flex flex-wrap items-center gap-1.5">
            @if ($latestRequest)
                <x-filament::badge :color="$latestRequest->status->color()">
                    {{ $latestRequest->bureau->label() }} {{ $latestRequest->request_type->label() }} · {{ $latestRequest->status->label() }}
                </x-filament::badge>
            @else
                <span class="font-semibold text-gray-950 dark:text-white">—</span>
            @endif
        </span>
    </span>

    <span class="fynn-dl-item">
        <span class="fynn-kpi-label block">PDF:</span>
        <span class="mt-0.5 flex flex-wrap items-center gap-1.5">
            @if ($pdfUrl)
                <x-filament::link :href="$pdfUrl" target="_blank" icon="heroicon-m-document-arrow-down">
                    Open
                </x-filament::link>
            @elseif ($latestPdf?->pdf_status)
                <x-filament::badge :color="$latestPdf->pdf_status->color()">
                    {{ $latestPdf->pdf_status->label() }}
                </x-filament::badge>
            @else
                <span class="font-semibold text-gray-950 dark:text-white">None</span>
            @endif
        </span>
    </span>
</span>
