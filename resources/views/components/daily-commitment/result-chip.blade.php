@props(['result' => null, 'muted' => 'No commitment'])

@if ($result instanceof \App\Enums\CommitmentResult)
    <span class="dc-chip ring-1 ring-inset {{ $result->chipClasses() }}">{{ $result->label() }}</span>
@else
    <span class="text-xs text-gray-400">{{ $muted }}</span>
@endif
