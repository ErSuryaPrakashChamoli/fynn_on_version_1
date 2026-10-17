@php
    /** @var array<string, mixed> $payload */
@endphp

{{--
    Renders the bureau's response whatever its shape: nested objects become
    labelled groups, lists of objects become small tables, scalars are shown
    as they are. Swap in a purpose-built layout once the live API's format
    is known.
--}}
<div class="text-sm">
    @include('filament.components.cibil-report-payload-node', ['node' => $payload])
</div>
