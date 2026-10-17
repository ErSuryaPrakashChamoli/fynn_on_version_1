@php
    /** @var mixed $node */
    $label = fn (string|int $key): string => is_int($key) ? '#'.($key + 1) : \Illuminate\Support\Str::headline($key);
    $scalar = fn (mixed $value): string => match (true) {
        is_bool($value) => $value ? 'Yes' : 'No',
        $value === null || $value === '' => '—',
        default => (string) $value,
    };
    $isRowList = is_array($node) && array_is_list($node) && $node !== []
        && collect($node)->every(fn (mixed $row): bool => is_array($row) && ! array_is_list($row)
            && collect($row)->every(fn (mixed $cell): bool => ! is_array($cell)));
@endphp

@if ($isRowList)
    @php($columns = collect($node)->flatMap(fn (array $row): array => array_keys($row))->unique()->values())
    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
        <table class="w-full divide-y divide-gray-200 text-start dark:divide-white/10">
            <thead class="bg-gray-50 dark:bg-white/5">
                <tr>
                    @foreach ($columns as $column)
                        <th class="px-3 py-2 text-start text-xs font-semibold text-gray-600 dark:text-gray-300">{{ $label($column) }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($node as $row)
                    <tr>
                        @foreach ($columns as $column)
                            <td class="px-3 py-2 text-gray-950 dark:text-white">{{ $scalar($row[$column] ?? null) }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@elseif (is_array($node))
    <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($node as $key => $value)
            @if (is_array($value))
                <div class="sm:col-span-2 lg:col-span-3">
                    <dt class="mb-2 text-xs font-bold uppercase tracking-wide text-gray-600 dark:text-gray-300">{{ $label($key) }}</dt>
                    <dd class="border-s-2 border-gray-200 ps-3 dark:border-white/10">
                        @include('filament.components.cibil-report-payload-node', ['node' => $value])
                    </dd>
                </div>
            @else
                <div>
                    <dt class="text-xs text-gray-500 dark:text-gray-400">{{ $label($key) }}</dt>
                    <dd class="break-words text-gray-950 dark:text-white">{{ $scalar($value) }}</dd>
                </div>
            @endif
        @endforeach
    </dl>
@else
    <span class="text-gray-950 dark:text-white">{{ $scalar($node) }}</span>
@endif
