@php
    use App\Filament\Resources\CibilReports\CibilReportResource;

    /** @var iterable<\App\Models\CibilReport> $reports */
@endphp

@if (collect($reports)->isEmpty())
    <div class="fynn-empty fynn-empty--compact">
        <span class="fynn-empty-icon"><x-filament::icon icon="heroicon-o-document-magnifying-glass" /></span>
        <span>No other reports for this PAN.</span>
    </div>
@else
    <ul class="fynn-list text-sm">
        @foreach ($reports as $report)
            <li class="fynn-list-row flex-wrap">
                <div class="fynn-list-main flex-wrap gap-2">
                    <x-filament::badge :color="$report->bureau->color()">
                        {{ $report->bureau->label() }}
                    </x-filament::badge>
                    <x-filament::badge :color="$report->scoreBand()?->color() ?? 'gray'">
                        {{ $report->score === null ? '—' : ($report->score < 300 ? 'NH' : $report->score) }}
                    </x-filament::badge>
                    <x-filament::badge :color="$report->status->color()">
                        {{ $report->status->label() }}
                    </x-filament::badge>
                    <span class="fynn-list-title">{{ $report->created_at?->format('d M Y, h:i A') }}</span>
                    <span class="fynn-list-meta">· {{ $report->requester?->name ?? 'Former user' }}</span>
                </div>
                <x-filament::link :href="CibilReportResource::getUrl('view', ['record' => $report])" icon="heroicon-m-arrow-top-right-on-square">
                    Open
                </x-filament::link>
            </li>
        @endforeach
    </ul>
@endif
