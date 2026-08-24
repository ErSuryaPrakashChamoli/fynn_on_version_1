@php
    $employee = $node['employee'];
    $children = $node['children'];
    $highlightId ??= null;
    $isHighlighted = $highlightId && $employee->id === $highlightId;
@endphp

<li>
    @include('filament.pages.partials.employee-hierarchy-node-label', ['person' => $employee, 'isHighlighted' => $isHighlighted, 'inline' => true])

    @if (! empty($children))
        <ul class="eh-vtree">
            @foreach ($children as $child)
                @include('filament.pages.partials.employee-hierarchy-node', ['node' => $child, 'highlightId' => $highlightId])
            @endforeach
        </ul>
    @endif
</li>
