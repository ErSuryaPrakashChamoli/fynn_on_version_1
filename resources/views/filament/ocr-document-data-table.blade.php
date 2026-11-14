@php
    $document->loadMissing(['schema', 'aiCustomerRecords']);
    $fields = $document->schema?->getFieldDefinitions() ?? [];
    $records = $document->aiCustomerRecords;
@endphp

<div class="space-y-4">
    <div class="flex items-center justify-between gap-4">
        <div>
            <h3 class="fynn-subheading mb-0.5">Extracted Customer Data</h3>
            <p class="fynn-list-meta">
                {{ $records->count() }} row{{ $records->count() === 1 ? '' : 's' }} extracted from this document.
            </p>
        </div>
    </div>

    @if ($records->isEmpty())
        <div class="fynn-empty fynn-empty--compact">
            <span class="fynn-empty-icon"><x-filament::icon icon="heroicon-o-table-cells" /></span>
            <span>No structured customer rows were extracted yet.</span>
        </div>
    @else
        {{-- overflow-x-auto + relative: table-scroll-nav.js adds its scroll arrows to this wide table. --}}
        <div class="fynn-table-wrap relative overflow-x-auto">
            <table class="fynn-table min-w-full">
                <thead>
                    <tr>
                        <th>#</th>
                        @foreach ($fields as $field)
                            <th>
                                {{ $field['label'] ?? $field['key'] ?? '' }}
                            </th>
                        @endforeach
                        <th class="text-end">Confidence</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($records as $index => $record)
                        <tr>
                            <td class="w-px whitespace-nowrap text-gray-500 dark:text-gray-400">{{ $index + 1 }}</td>
                            @foreach ($fields as $field)
                                @php($key = $field['key'] ?? '')
                                <td class="max-w-xs whitespace-nowrap">
                                    {{ data_get($record->data, $key) ?: '-' }}
                                </td>
                            @endforeach
                            <td class="fynn-num whitespace-nowrap">
                                {{ $record->confidence_score === null ? '-' : number_format((float) $record->confidence_score * 100, 1) . '%' }}
                            </td>
                            <td class="whitespace-nowrap">
                                <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ring-inset ring-current/20
                                    {{ $record->status === 'approved' ? 'bg-success-50 text-success-700 dark:bg-success-400/10 dark:text-success-400' : ($record->status === 'rejected' ? 'bg-danger-50 text-danger-700 dark:bg-danger-400/10 dark:text-danger-400' : 'bg-warning-50 text-warning-700 dark:bg-warning-400/10 dark:text-warning-400') }}">
                                    {{ ucfirst($record->status) }}
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
