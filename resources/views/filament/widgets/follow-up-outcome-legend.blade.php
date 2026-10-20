{{-- The verdict chips open the outcome list (ShowsFollowUpOutcomes): the
     modal opens at once in the browser, its list follows on the round trip. --}}
<span class="followup-outcome-legend">
    @foreach ([
        'on_time' => ['class' => 'on-time', 'text' => '✓ On time'],
        'late' => ['class' => 'late', 'text' => '⏱ Late'],
        'missed' => ['class' => 'missed', 'text' => '✗ Missed'],
        'open' => ['class' => 'open', 'text' => 'Open'],
    ] as $filter => $chip)
        <button type="button"
            class="followup-outcome followup-outcome--{{ $chip['class'] }} followup-outcome--clickable"
            title="Show every {{ strtolower($this::outcomeListFilters()[$filter]['label']) }} follow-up"
            x-on:click="$dispatch('open-modal', { id: @js($this->outcomeListModalId()) }); $wire.openOutcomeList(@js($filter))">{{ $chip['text'] }}</button>
    @endforeach
    <span class="followup-outcome followup-outcome--overloaded" title="Callers booked past {{ \App\Services\FollowUpMonitorService::DAILY_CAPACITY }} follow-ups a day">⚠ Over {{ \App\Services\FollowUpMonitorService::DAILY_CAPACITY }}/day</span>
</span>
