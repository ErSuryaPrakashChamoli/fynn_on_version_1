@php
    use App\Filament\Resources\CibilReports\CibilReportResource;

    /** @var iterable<\App\Models\CibilReport> $reports */
@endphp

@if (collect($reports)->isEmpty())
    <p class="text-sm text-gray-500 dark:text-gray-400">No other reports for this PAN.</p>
@else
    <ul class="divide-y divide-gray-200 text-sm dark:divide-white/10">
        @foreach ($reports as $report)
            <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                <div class="flex flex-wrap items-center gap-2">
                    <x-filament::badge :color="$report->bureau->color()">
                        {{ $report->bureau->label() }}
                    </x-filament::badge>
                    <x-filament::badge :color="$report->scoreBand()?->color() ?? 'gray'">
                        {{ $report->score === null ? '—' : ($report->score < 300 ? 'NH' : $report->score) }}
                    </x-filament::badge>
                    <x-filament::badge :color="$report->status->color()">
                        {{ $report->status->label() }}
                    </x-filament::badge>
                    <span class="text-gray-950 dark:text-white">{{ $report->created_at?->format('d M Y, h:i A') }}</span>
                    <span class="text-gray-500 dark:text-gray-400">· {{ $report->requester?->name ?? 'Former user' }}</span>
                </div>
                <x-filament::link :href="CibilReportResource::getUrl('view', ['record' => $report])" icon="heroicon-m-arrow-top-right-on-square">
                    Open
                </x-filament::link>
            </li>
        @endforeach
    </ul>
@endif
