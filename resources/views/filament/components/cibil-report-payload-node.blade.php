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
    {{-- overflow-x-auto + relative: table-scroll-nav.js adds its scroll arrows to this box. --}}
    <div class="fynn-table-wrap relative overflow-x-auto">
        <table class="fynn-table">
            <thead>
                <tr>
                    @foreach ($columns as $column)
                        <th>{{ $label($column) }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($node as $row)
                    <tr>
                        @foreach ($columns as $column)
                            <td>{{ $scalar($row[$column] ?? null) }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@elseif (is_array($node))
    <dl class="fynn-dl">
        @foreach ($node as $key => $value)
            @if (is_array($value))
                <div class="col-span-full">
                    <dt class="fynn-subheading mt-1">{{ $label($key) }}</dt>
                    <dd class="border-s-2 border-(--fynn-border) ps-3">
                        @include('filament.components.cibil-report-payload-node', ['node' => $value])
                    </dd>
                </div>
            @else
                <div class="fynn-dl-item">
                    <dt>{{ $label($key) }}</dt>
                    <dd>{{ $scalar($value) }}</dd>
                </div>
            @endif
        @endforeach
    </dl>
@else
    <span class="text-gray-950 dark:text-white">{{ $scalar($node) }}</span>
@endif
