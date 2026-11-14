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
    <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div class="fynn-kpi" style="--fynn-kpi-accent: #7c3aed">
            <dt class="fynn-kpi-head">
                <span class="fynn-kpi-icon"><x-filament::icon icon="heroicon-o-chart-bar-square" /></span>
                <span class="fynn-kpi-label">Experian score</span>
            </dt>
            <dd @class(['fynn-kpi-value', 'text-base' => $score === null])>{{ $score ?? $missing }}</dd>
        </div>
        <div class="fynn-kpi" style="--fynn-kpi-accent: #0ea5e9">
            <dt class="fynn-kpi-head">
                <span class="fynn-kpi-icon"><x-filament::icon icon="heroicon-o-calendar-days" /></span>
                <span class="fynn-kpi-label">Report date</span>
            </dt>
            <dd class="fynn-kpi-value text-base">{{ trim(($header['report_date'] ?? '').' '.($header['report_time'] ?? '')) ?: $missing }}</dd>
        </div>
        <div class="fynn-kpi" style="--fynn-kpi-accent: #64748b">
            <dt class="fynn-kpi-head">
                <span class="fynn-kpi-icon"><x-filament::icon icon="heroicon-o-hashtag" /></span>
                <span class="fynn-kpi-label">Report number</span>
            </dt>
            <dd class="fynn-kpi-value font-mono text-base">{{ $header['report_number'] ?? $missing }}</dd>
        </div>
        <div class="fynn-kpi" style="--fynn-kpi-accent: #64748b">
            <dt class="fynn-kpi-head">
                <span class="fynn-kpi-icon"><x-filament::icon icon="heroicon-o-tag" /></span>
                <span class="fynn-kpi-label">Report version</span>
            </dt>
            <dd class="fynn-kpi-value text-base">{{ $header['version'] ?? $missing }}</dd>
        </div>
    </dl>

    @foreach ($sections as $heading => $values)
        <div>
            <div class="fynn-subheading">{{ $heading }}</div>
            <dl class="fynn-dl">
                @foreach ($values as $label => $value)
                    <div class="fynn-dl-item">
                        <dt>{{ $label }}</dt>
                        <dd class="{{ $value === null ? 'font-medium text-gray-400 dark:text-gray-500' : '' }}">{{ $value ?? $missing }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    @endforeach

    @foreach ($tables as $heading => $rows)
        <div>
            <div class="fynn-subheading">{{ $heading }} ({{ count($rows) }})</div>

            @if ($rows === [])
                <div class="fynn-empty fynn-empty--compact">
                    <span>{{ $missing }}</span>
                </div>
            @else
                {{-- overflow-x-auto + relative: table-scroll-nav.js adds its scroll arrows to this box. --}}
                <div class="fynn-table-wrap relative overflow-x-auto">
                    <table class="fynn-table">
                        <thead>
                            <tr>
                                @foreach (array_keys($rows[0]) as $column)
                                    <th>{{ $column }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                <tr>
                                    @foreach ($row as $value)
                                        <td class="whitespace-nowrap">{{ $value ?? '—' }}</td>
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
        <details class="fynn-card fynn-card--muted group/other p-0">
            <summary class="flex cursor-pointer list-none items-center gap-2 px-4 py-3 text-xs font-bold uppercase tracking-wide text-gray-600 dark:text-gray-300 [&::-webkit-details-marker]:hidden">
                <x-filament::icon icon="heroicon-m-chevron-right" class="size-4 shrink-0 transition-transform group-open/other:rotate-90" />
                Other report sections ({{ count($other) }})
            </summary>
            <div class="border-t border-(--fynn-border-soft) px-4 py-4">
                @include('filament.components.cibil-report-payload-node', ['node' => $other])
            </div>
        </details>
    @endif
</div>
