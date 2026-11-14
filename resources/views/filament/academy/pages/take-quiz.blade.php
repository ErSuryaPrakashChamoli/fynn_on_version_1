<x-filament-panels::page class="academy-page">
    @php($history = $this->getHistory())

    @if ($attempt && $showResult)
        {{-- Result of the attempt just submitted --}}
        <x-filament::section icon="heroicon-o-trophy">
            <x-slot name="heading">Your Result</x-slot>

            <div class="academy-stats">
                <div class="academy-stat">
                    <p class="academy-stat__label"><x-filament::icon icon="heroicon-m-hashtag" /> Score</p>
                    <p class="academy-stat__value">
                        {{ $attempt->score }} / {{ $attempt->total_marks }}
                    </p>
                </div>

                <div class="academy-stat">
                    <p class="academy-stat__label"><x-filament::icon icon="heroicon-m-chart-pie" /> Percentage</p>
                    <p class="academy-stat__value">{{ $attempt->percentage }}%</p>
                </div>

                <div class="academy-stat">
                    <p class="academy-stat__label"><x-filament::icon icon="heroicon-m-flag" /> Outcome</p>
                    <div class="academy-stat__badge">
                        <x-filament::badge
                            :color="$attempt->passed ? 'success' : 'danger'"
                            :icon="$attempt->passed ? 'heroicon-m-check-circle' : 'heroicon-m-x-circle'"
                            size="lg"
                        >
                            {{ $attempt->passed ? 'Passed' : 'Not passed' }}
                        </x-filament::badge>
                    </div>
                </div>
            </div>

            <x-slot name="footer">
                <div class="academy-actions">
                    <x-filament::button
                        tag="a"
                        color="gray"
                        icon="heroicon-m-arrow-uturn-left"
                        :href="\App\Filament\Academy\Pages\CoursePlayer::getUrl(['enrollment' => $enrollment->getKey()], panel: 'academy')"
                    >
                        Back to course
                    </x-filament::button>

                    <x-filament::button wire:click="startAttempt" color="warning" icon="heroicon-m-arrow-path">
                        Try again
                    </x-filament::button>
                </div>
            </x-slot>
        </x-filament::section>
    @elseif ($attempt)
        {{-- An attempt is open: render the questions --}}
        <form wire:submit="submit">
            <div class="academy-stack">
                @foreach ($this->getQuestions() as $index => $question)
                    <x-filament::section icon="heroicon-o-question-mark-circle">
                        <x-slot name="heading">
                            Question {{ $index + 1 }}
                        </x-slot>

                        <p class="academy-question">
                            {{ $question->question }}
                        </p>

                        <div class="academy-options">
                            @foreach ($question->options ?? [] as $key => $label)
                                <label class="academy-option">
                                    <input
                                        type="radio"
                                        class="fi-radio-input"
                                        wire:model="answers.{{ $question->getKey() }}"
                                        value="{{ $key }}"
                                    />
                                    <span class="academy-option__text">
                                        <span class="academy-option__key">{{ $key }}.</span> {{ $label }}
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </x-filament::section>
                @endforeach

                <div class="academy-actions academy-actions--end">
                    <x-filament::button type="submit" size="lg" icon="heroicon-m-paper-airplane">
                        Submit answers
                    </x-filament::button>
                </div>
            </div>
        </form>
    @else
        {{-- Start screen --}}
        <x-filament::section icon="heroicon-o-clipboard-document-check">
            <x-slot name="heading">{{ $quiz->title }}</x-slot>

            @if ($quiz->description)
                <x-slot name="description">{{ $quiz->description }}</x-slot>
            @endif

            <dl class="academy-stats">
                <div class="academy-stat">
                    <dt class="academy-stat__label"><x-filament::icon icon="heroicon-m-question-mark-circle" /> Questions</dt>
                    <dd class="academy-stat__value academy-stat__value--sm">{{ $this->getQuestions()->count() }}</dd>
                </div>
                <div class="academy-stat">
                    <dt class="academy-stat__label"><x-filament::icon icon="heroicon-m-flag" /> Pass mark</dt>
                    <dd class="academy-stat__value academy-stat__value--sm">{{ $quiz->pass_percentage }}%</dd>
                </div>
                <div class="academy-stat">
                    <dt class="academy-stat__label"><x-filament::icon icon="heroicon-m-clock" /> Time limit</dt>
                    <dd class="academy-stat__value academy-stat__value--sm">
                        {{ $quiz->time_limit_minutes ? $quiz->time_limit_minutes.' min' : 'None' }}
                    </dd>
                </div>
            </dl>

            <x-slot name="footer">
                <div class="academy-actions">
                    <x-filament::button wire:click="startAttempt" icon="heroicon-m-play-circle">Start</x-filament::button>

                    <x-filament::button
                        tag="a"
                        color="gray"
                        icon="heroicon-m-arrow-uturn-left"
                        :href="\App\Filament\Academy\Pages\CoursePlayer::getUrl(['enrollment' => $enrollment->getKey()], panel: 'academy')"
                    >
                        Back to course
                    </x-filament::button>
                </div>
            </x-slot>
        </x-filament::section>
    @endif

    @if ($history->isNotEmpty())
        <x-filament::section collapsible collapsed icon="heroicon-o-clock">
            <x-slot name="heading">Your Previous Attempts</x-slot>

            <div class="academy-table-wrap">
                <table class="academy-table">
                    <thead>
                        <tr>
                            <th>Attempt</th>
                            <th>Score</th>
                            <th>Percentage</th>
                            <th>Outcome</th>
                            <th>Completed</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($history as $past)
                            <tr>
                                <td class="academy-table__strong">#{{ $past->attempt_number }}</td>
                                <td>{{ $past->score }} / {{ $past->total_marks }}</td>
                                <td>{{ $past->percentage }}%</td>
                                <td>
                                    <x-filament::badge :color="$past->passed ? 'success' : 'danger'">
                                        {{ $past->passed ? 'Passed' : 'Failed' }}
                                    </x-filament::badge>
                                </td>
                                <td class="academy-table__muted">
                                    {{ $past->completed_at?->format('d M Y, H:i') }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
