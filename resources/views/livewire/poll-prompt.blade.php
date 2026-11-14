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
            class="fixed inset-0 z-[9999] flex items-center-safe justify-center overflow-y-auto bg-gray-950/70 p-4 backdrop-blur-sm"
            role="dialog"
            aria-modal="true"
            aria-labelledby="poll-prompt-heading"
        >
            <div class="fynn-card fynn-card--flush w-full max-w-xl shadow-2xl">
                <div class="flex items-start gap-4 border-b border-gray-950/5 px-6 py-5 dark:border-white/10" style="background: linear-gradient(180deg, var(--fynn-surface-2), var(--fynn-surface))">
                    <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-warning-50 text-warning-600 ring-1 ring-inset ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-400 dark:ring-warning-400/25">
                        <x-filament::icon icon="heroicon-o-hand-thumb-up" class="size-6" />
                    </span>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="text-xs font-bold uppercase tracking-wider text-warning-600 dark:text-warning-400">
                                Your vote is required
                            </p>

                            @if ($pendingCount > 1)
                                <span class="fynn-chip">1 of {{ $pendingCount }}</span>
                            @endif
                        </div>

                        <h2 id="poll-prompt-heading" class="mt-1 text-lg font-extrabold leading-snug tracking-tight text-gray-950 dark:text-white">
                            {{ $poll->title }}
                        </h2>

                        <p class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                            <span class="inline-flex items-center gap-1">
                                <x-filament::icon icon="heroicon-m-user" class="size-3.5 text-gray-400" />
                                {{ $poll->creator?->name ?? 'Admin' }}
                            </span>
                            <span class="inline-flex items-center gap-1">
                                <x-filament::icon icon="heroicon-m-clock" class="size-3.5 text-gray-400" />
                                {{ $poll->created_at?->format('d M Y h:i A') }}
                            </span>
                            @if ($poll->expires_at)
                                <span class="inline-flex items-center gap-1">
                                    <x-filament::icon icon="heroicon-m-calendar-days" class="size-3.5 text-gray-400" />
                                    closes {{ $poll->expires_at->format('d M Y h:i A') }}
                                </span>
                            @endif
                            @if ($poll->is_anonymous)
                                <span class="fynn-chip text-success-700 dark:text-success-400">
                                    <x-filament::icon icon="heroicon-m-eye-slash" class="size-3.5" />
                                    anonymous
                                </span>
                            @endif
                        </p>
                    </div>
                </div>

                <div class="max-h-[50vh] space-y-5 overflow-y-auto px-6 py-5">
                    <p class="whitespace-pre-line break-words text-[0.9375rem] font-medium leading-7 text-gray-800 dark:text-gray-100">{{ $poll->question }}</p>

                    <div>
                        <label for="poll-prompt-option" class="mb-1.5 block text-sm font-semibold text-gray-950 dark:text-white">Your answer</label>
                        <div @class(['fi-input-wrp', 'fi-invalid' => $errors->has('option')])>
                            <select
                                id="poll-prompt-option"
                                wire:model.live="option"
                                class="fi-select-input"
                            >
                                <option value="">Select an option</option>
                                @foreach ($poll->optionList() as $choice)
                                    <option value="{{ $choice }}">{{ $choice }}</option>
                                @endforeach
                            </select>
                        </div>
                        @error('option')
                            <p class="mt-1.5 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>
                        @enderror
                    </div>

                    @php
                        // Only once an answer is picked: the reasons depend on it.
                        $reasonChoices = filled($option) ? $poll->reasonsFor($option) : [];
                    @endphp
                    @if ($reasonChoices !== [])
                        <div wire:key="poll-reason-{{ $current->getKey() }}-{{ md5((string) $option) }}">
                            <label for="poll-prompt-reason" class="mb-1.5 block text-sm font-semibold text-gray-950 dark:text-white">Reason</label>
                            <div @class(['fi-input-wrp', 'fi-invalid' => $errors->has('reason')])>
                                <select
                                    id="poll-prompt-reason"
                                    wire:model="reason"
                                    class="fi-select-input"
                                >
                                    <option value="">Select a reason</option>
                                    @foreach ($reasonChoices as $choice)
                                        <option value="{{ $choice }}">{{ $choice }}</option>
                                    @endforeach
                                </select>
                            </div>
                            @error('reason')
                                <p class="mt-1.5 text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>
                            @enderror
                        </div>
                    @endif

                    @if ($poll->allow_comment)
                        <div>
                            <label for="poll-prompt-comment" class="mb-1.5 block text-sm font-semibold text-gray-950 dark:text-white">Comment (optional)</label>
                            <div class="fi-input-wrp fi-fo-textarea">
                                <textarea
                                    id="poll-prompt-comment"
                                    wire:model="comment"
                                    rows="3"
                                    maxlength="1000"
                                ></textarea>
                            </div>
                        </div>
                    @endif
                </div>

                <div class="flex flex-col gap-3 border-t border-gray-950/5 bg-gray-50 px-6 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-white/10 dark:bg-white/5">
                    <p class="flex items-start gap-2 text-xs text-gray-500 dark:text-gray-400">
                        @if ($poll->is_anonymous)
                            <x-filament::icon icon="heroicon-m-eye-slash" class="mt-px size-4 shrink-0 text-gray-400" />
                            <span>Your answer is recorded without your name.</span>
                        @else
                            <x-filament::icon icon="heroicon-m-user" class="mt-px size-4 shrink-0 text-gray-400" />
                            <span>Your answer is recorded against your name for the person who raised it.</span>
                        @endif
                    </p>

                    <x-filament::button
                        wire:click="submitVote({{ $current->getKey() }})"
                        wire:loading.attr="disabled"
                        icon="heroicon-m-check-circle"
                        class="shrink-0"
                    >
                        Submit vote
                    </x-filament::button>
                </div>
            </div>
        </div>
    @endif
</div>
