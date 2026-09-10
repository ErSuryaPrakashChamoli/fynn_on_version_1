<x-filament-panels::page>
    @php($history = $this->getHistory())

    @if ($attempt && $showResult)
        {{-- Result of the attempt just submitted --}}
        <x-filament::section>
            <x-slot name="heading">Your Result</x-slot>

            <div class="flex flex-wrap items-center gap-8">
                <div>
                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Score</p>
                    <p class="text-2xl font-semibold text-gray-950 dark:text-white">
                        {{ $attempt->score }} / {{ $attempt->total_marks }}
                    </p>
                </div>

                <div>
                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Percentage</p>
                    <p class="text-2xl font-semibold text-gray-950 dark:text-white">{{ $attempt->percentage }}%</p>
                </div>

                <div>
                    <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Outcome</p>
                    <x-filament::badge :color="$attempt->passed ? 'success' : 'danger'" size="lg">
                        {{ $attempt->passed ? 'Passed' : 'Not passed' }}
                    </x-filament::badge>
                </div>
            </div>

            <x-slot name="footer">
                <div class="flex flex-wrap items-center gap-3">
                    <x-filament::button
                        tag="a"
                        color="gray"
                        :href="\App\Filament\Academy\Pages\CoursePlayer::getUrl(['enrollment' => $enrollment->getKey()], panel: 'academy')"
                    >
                        Back to course
                    </x-filament::button>

                    <x-filament::button wire:click="startAttempt" color="warning">
                        Try again
                    </x-filament::button>
                </div>
            </x-slot>
        </x-filament::section>
    @elseif ($attempt)
        {{-- An attempt is open: render the questions --}}
        <form wire:submit="submit">
            <div class="space-y-4">
                @foreach ($this->getQuestions() as $index => $question)
                    <x-filament::section>
                        <x-slot name="heading">
                            Question {{ $index + 1 }}
                        </x-slot>

                        <p class="mb-4 text-sm font-medium text-gray-900 dark:text-gray-100">
                            {{ $question->question }}
                        </p>

                        <div class="space-y-2">
                            @foreach ($question->options ?? [] as $key => $label)
                                <label class="flex cursor-pointer items-start gap-2.5 rounded-lg border border-gray-200 p-3 transition hover:bg-gray-50 dark:border-white/10 dark:hover:bg-white/5">
                                    <input
                                        type="radio"
                                        class="mt-0.5 border-gray-300 text-primary-600 focus:ring-primary-500"
                                        wire:model="answers.{{ $question->getKey() }}"
                                        value="{{ $key }}"
                                    />
                                    <span class="text-sm text-gray-700 dark:text-gray-300">
                                        <span class="font-semibold">{{ $key }}.</span> {{ $label }}
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </x-filament::section>
                @endforeach

                <div class="flex justify-end">
                    <x-filament::button type="submit" size="lg">
                        Submit answers
                    </x-filament::button>
                </div>
            </div>
        </form>
    @else
        {{-- Start screen --}}
        <x-filament::section>
            <x-slot name="heading">{{ $quiz->title }}</x-slot>

            @if ($quiz->description)
                <x-slot name="description">{{ $quiz->description }}</x-slot>
            @endif

            <dl class="grid gap-4 sm:grid-cols-3">
                <div>
                    <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Questions</dt>
                    <dd class="text-lg font-semibold text-gray-950 dark:text-white">{{ $this->getQuestions()->count() }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Pass mark</dt>
                    <dd class="text-lg font-semibold text-gray-950 dark:text-white">{{ $quiz->pass_percentage }}%</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Time limit</dt>
                    <dd class="text-lg font-semibold text-gray-950 dark:text-white">
                        {{ $quiz->time_limit_minutes ? $quiz->time_limit_minutes.' min' : 'None' }}
                    </dd>
                </div>
            </dl>

            <x-slot name="footer">
                <div class="flex flex-wrap items-center gap-3">
                    <x-filament::button wire:click="startAttempt">Start</x-filament::button>

                    <x-filament::button
                        tag="a"
                        color="gray"
                        :href="\App\Filament\Academy\Pages\CoursePlayer::getUrl(['enrollment' => $enrollment->getKey()], panel: 'academy')"
                    >
                        Back to course
                    </x-filament::button>
                </div>
            </x-slot>
        </x-filament::section>
    @endif

    @if ($history->isNotEmpty())
        <x-filament::section collapsible collapsed>
            <x-slot name="heading">Your Previous Attempts</x-slot>

            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <th class="pb-2">Attempt</th>
                        <th class="pb-2">Score</th>
                        <th class="pb-2">Percentage</th>
                        <th class="pb-2">Outcome</th>
                        <th class="pb-2">Completed</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                    @foreach ($history as $past)
                        <tr>
                            <td class="py-2 text-gray-700 dark:text-gray-300">#{{ $past->attempt_number }}</td>
                            <td class="py-2 text-gray-700 dark:text-gray-300">{{ $past->score }} / {{ $past->total_marks }}</td>
                            <td class="py-2 text-gray-700 dark:text-gray-300">{{ $past->percentage }}%</td>
                            <td class="py-2">
                                <x-filament::badge :color="$past->passed ? 'success' : 'danger'">
                                    {{ $past->passed ? 'Passed' : 'Failed' }}
                                </x-filament::badge>
                            </td>
                            <td class="py-2 text-gray-500 dark:text-gray-400">
                                {{ $past->completed_at?->format('d M Y, H:i') }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-filament::section>
    @endif
</x-filament-panels::page>
