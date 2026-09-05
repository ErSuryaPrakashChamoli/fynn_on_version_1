@props(['stage' => null, 'muted' => '—'])

@if ($stage instanceof \App\Enums\CommitmentStage)
    <span class="dc-chip ring-1 ring-inset {{ $stage->chipClasses() }}">
        <span class="dc-dot" style="background: {{ $stage->hex() }}"></span>
        {{ $stage->label() }}
    </span>
@else
    <span class="text-xs text-gray-400">{{ $muted }}</span>
@endif
