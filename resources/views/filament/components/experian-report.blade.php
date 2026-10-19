@php
    /** @var \App\Support\ExperianReport $report */
    /** @var int|null $score */
    $header = $report->header();
    $missing = 'Not available in this report';
    $sections = [
        'Applicant' => $report->applicant(),
        'Credit summary' => $report->creditSummary(),
    ];
    $tables = [
        'Credit accounts' => $report->accounts(),
        'Enquiries' => $report->enquiries(),
    ];
    $other = $report->otherSections();
@endphp

{{-- Everything is escaped ({{ }}); report data is never rendered as HTML. --}}
<div class="space-y-6 text-sm">
    <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2 lg:grid-cols-4">
        <div>
            <dt class="text-xs text-gray-500 dark:text-gray-400">Experian score</dt>
            <dd class="font-semibold text-gray-950 dark:text-white">{{ $score ?? $missing }}</dd>
        </div>
        <div>
            <dt class="text-xs text-gray-500 dark:text-gray-400">Report date</dt>
            <dd class="text-gray-950 dark:text-white">{{ trim(($header['report_date'] ?? '').' '.($header['report_time'] ?? '')) ?: $missing }}</dd>
        </div>
        <div>
            <dt class="text-xs text-gray-500 dark:text-gray-400">Report number</dt>
            <dd class="font-mono text-gray-950 dark:text-white">{{ $header['report_number'] ?? $missing }}</dd>
        </div>
        <div>
            <dt class="text-xs text-gray-500 dark:text-gray-400">Report version</dt>
            <dd class="text-gray-950 dark:text-white">{{ $header['version'] ?? $missing }}</dd>
        </div>
    </dl>

    @foreach ($sections as $heading => $values)
        <div>
            <div class="mb-2 text-xs font-bold uppercase tracking-wide text-gray-600 dark:text-gray-300">{{ $heading }}</div>
            <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($values as $label => $value)
                    <div>
                        <dt class="text-xs text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                        <dd class="break-words {{ $value === null ? 'text-gray-400 dark:text-gray-500' : 'text-gray-950 dark:text-white' }}">{{ $value ?? $missing }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    @endforeach

    @foreach ($tables as $heading => $rows)
        <div>
            <div class="mb-2 text-xs font-bold uppercase tracking-wide text-gray-600 dark:text-gray-300">{{ $heading }} ({{ count($rows) }})</div>

            @if ($rows === [])
                <p class="text-gray-400 dark:text-gray-500">{{ $missing }}</p>
            @else
                <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                    <table class="w-full divide-y divide-gray-200 text-start dark:divide-white/10">
                        <thead class="bg-gray-50 dark:bg-white/5">
                            <tr>
                                @foreach (array_keys($rows[0]) as $column)
                                    <th class="whitespace-nowrap px-3 py-2 text-start text-xs font-semibold text-gray-600 dark:text-gray-300">{{ $column }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                            @foreach ($rows as $row)
                                <tr>
                                    @foreach ($row as $value)
                                        <td class="whitespace-nowrap px-3 py-2 text-gray-950 dark:text-white">{{ $value ?? '—' }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endforeach

    @if ($other !== [])
        <details class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
            <summary class="cursor-pointer text-xs font-bold uppercase tracking-wide text-gray-600 dark:text-gray-300">Other report sections ({{ count($other) }})</summary>
            <div class="mt-3">
                @include('filament.components.cibil-report-payload-node', ['node' => $other])
            </div>
        </details>
    @endif
</div>
