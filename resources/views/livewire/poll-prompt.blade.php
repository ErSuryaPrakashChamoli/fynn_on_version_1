{{--
    The mandatory-poll block (App\Livewire\PollPrompt). Deliberately not a
    Filament modal: it cannot be dismissed, closed on escape or clicked
    past — casting the vote is the only way back into the LMS. Polls so a
    poll sent while the page is open still blocks it.
--}}
<div wire:poll.30s>
    @if ($current)
        @php
            $poll = $current->poll;
        @endphp

        <div
            wire:key="poll-{{ $current->getKey() }}"
            class="fixed inset-0 z-[9999] flex items-center justify-center overflow-y-auto bg-gray-950/70 p-4 backdrop-blur-sm"
            role="dialog"
            aria-modal="true"
            aria-labelledby="poll-prompt-heading"
        >
            <div class="w-full max-w-xl rounded-xl bg-white shadow-2xl ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <div class="flex items-start gap-3 border-b border-gray-200 px-6 py-5 dark:border-white/10">
                    <x-filament::icon
                        icon="heroicon-o-hand-thumb-up"
                        class="mt-0.5 h-7 w-7 shrink-0 text-warning-600 dark:text-warning-400"
                    />

                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-semibold uppercase tracking-wide text-warning-600 dark:text-warning-400">
                            Your vote is required
                            @if ($pendingCount > 1)
                                <span class="font-normal text-gray-400">· 1 of {{ $pendingCount }}</span>
                            @endif
                        </p>

                        <h2 id="poll-prompt-heading" class="mt-1 text-lg font-semibold text-gray-950 dark:text-white">
                            {{ $poll->title }}
                        </h2>

                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ $poll->creator?->name ?? 'Admin' }} · {{ $poll->created_at?->format('d M Y h:i A') }}
                            @if ($poll->expires_at)
                                · closes {{ $poll->expires_at->format('d M Y h:i A') }}
                            @endif
                            @if ($poll->is_anonymous)
                                · <span class="font-medium text-success-600 dark:text-success-400">anonymous</span>
                            @endif
                        </p>
                    </div>
                </div>

                <div class="max-h-[50vh] space-y-4 overflow-y-auto px-6 py-5">
                    <p class="whitespace-pre-line break-words text-sm leading-6 text-gray-700 dark:text-gray-200">{{ $poll->question }}</p>

                    <div>
                        <label for="poll-prompt-option" class="mb-1 block text-sm font-medium text-gray-950 dark:text-white">Your answer</label>
                        <select
                            id="poll-prompt-option"
                            wire:model.live="option"
                            class="fi-input fi-select-input block w-full rounded-lg border-none bg-white py-2 text-sm text-gray-950 shadow-sm ring-1 ring-gray-950/10 dark:bg-white/5 dark:text-white dark:ring-white/20"
                        >
                            <option value="">Select an option</option>
                            @foreach ($poll->optionList() as $choice)
                                <option value="{{ $choice }}">{{ $choice }}</option>
                            @endforeach
                        </select>
                        @error('option')
                            <p class="mt-1 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>
                        @enderror
                    </div>

                    @php
                        // Only once an answer is picked: the reasons depend on it.
                        $reasonChoices = filled($option) ? $poll->reasonsFor($option) : [];
                    @endphp
                    @if ($reasonChoices !== [])
                        <div wire:key="poll-reason-{{ $current->getKey() }}-{{ md5((string) $option) }}">
                            <label for="poll-prompt-reason" class="mb-1 block text-sm font-medium text-gray-950 dark:text-white">Reason</label>
                            <select
                                id="poll-prompt-reason"
                                wire:model="reason"
                                class="fi-input fi-select-input block w-full rounded-lg border-none bg-white py-2 text-sm text-gray-950 shadow-sm ring-1 ring-gray-950/10 dark:bg-white/5 dark:text-white dark:ring-white/20"
                            >
                                <option value="">Select a reason</option>
                                @foreach ($reasonChoices as $choice)
                                    <option value="{{ $choice }}">{{ $choice }}</option>
                                @endforeach
                            </select>
                            @error('reason')
                                <p class="mt-1 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>
                            @enderror
                        </div>
                    @endif

                    @if ($poll->allow_comment)
                        <div>
                            <label for="poll-prompt-comment" class="mb-1 block text-sm font-medium text-gray-950 dark:text-white">Comment (optional)</label>
                            <textarea
                                id="poll-prompt-comment"
                                wire:model="comment"
                                rows="3"
                                maxlength="1000"
                                class="fi-input block w-full rounded-lg border-none bg-white py-2 text-sm text-gray-950 shadow-sm ring-1 ring-gray-950/10 dark:bg-white/5 dark:text-white dark:ring-white/20"
                            ></textarea>
                        </div>
                    @endif
                </div>

                <div class="flex flex-col gap-3 border-t border-gray-200 px-6 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-white/10">
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        @if ($poll->is_anonymous)
                            Your answer is recorded without your name.
                        @else
                            Your answer is recorded against your name for the person who raised it.
                        @endif
                    </p>

                    <x-filament::button
                        wire:click="submitVote({{ $current->getKey() }})"
                        wire:loading.attr="disabled"
                        icon="heroicon-m-check-circle"
                    >
                        Submit vote
                    </x-filament::button>
                </div>
            </div>
        </div>
    @endif
</div>
