@php
    $document->loadMissing(['schema', 'aiCustomerRecords']);
    $fields = $document->schema?->getFieldDefinitions() ?? [];
    $records = $document->aiCustomerRecords;
@endphp

<div class="space-y-4">
    <div class="flex items-center justify-between gap-4">
        <div>
            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Extracted Customer Data</h3>
            <p class="text-xs text-gray-500 dark:text-gray-400">
                {{ $records->count() }} row{{ $records->count() === 1 ? '' : 's' }} extracted from this document.
            </p>
        </div>
    </div>

    @if ($records->isEmpty())
        <div class="rounded-lg border border-dashed border-gray-300 p-6 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
            No structured customer rows were extracted yet.
        </div>
    @else
        <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
            <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-200">#</th>
                        @foreach ($fields as $field)
                            <th class="whitespace-nowrap px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-200">
                                {{ $field['label'] ?? $field['key'] ?? '' }}
                            </th>
                        @endforeach
                        <th class="whitespace-nowrap px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-200">Confidence</th>
                        <th class="whitespace-nowrap px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-200">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white dark:divide-gray-800 dark:bg-gray-900">
                    @foreach ($records as $index => $record)
                        <tr>
                            <td class="whitespace-nowrap px-3 py-2 text-gray-500">{{ $index + 1 }}</td>
                            @foreach ($fields as $field)
                                @php($key = $field['key'] ?? '')
                                <td class="max-w-xs whitespace-nowrap px-3 py-2 text-gray-900 dark:text-gray-100">
                                    {{ data_get($record->data, $key) ?: '-' }}
                                </td>
                            @endforeach
                            <td class="whitespace-nowrap px-3 py-2 text-gray-700 dark:text-gray-200">
                                {{ $record->confidence_score === null ? '-' : number_format((float) $record->confidence_score * 100, 1) . '%' }}
                            </td>
                            <td class="whitespace-nowrap px-3 py-2">
                                <span class="inline-flex rounded-full px-2 py-1 text-xs font-medium
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
