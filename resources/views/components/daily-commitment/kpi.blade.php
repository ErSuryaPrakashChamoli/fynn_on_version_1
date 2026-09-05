@props(['label', 'value', 'hint' => null, 'accent' => null])

<div class="dc-card" @if ($accent) style="border-color: {{ $accent }}55; box-shadow: inset 3px 0 0 0 {{ $accent }}" @endif>
    <div class="dc-card-label">{{ $label }}</div>
    <div class="dc-card-value">{{ $value }}</div>
    @if ($hint)
        <div class="dc-card-hint">{{ $hint }}</div>
    @endif
</div>
