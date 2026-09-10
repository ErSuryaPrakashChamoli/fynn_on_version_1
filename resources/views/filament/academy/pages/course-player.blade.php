<x-filament-panels::page>
    @php($lesson = $this->getCurrentLesson())
    @php($completedIds = $this->getCompletedLessonIds())
    @php($quiz = $this->getCurrentQuiz())
    @php($assessment = $this->getFinalAssessment())

    <div class="grid gap-4 lg:grid-cols-[20rem_minmax(0,1fr)]">
        {{-- Course outline --}}
        <x-filament::section class="lg:sticky lg:top-4 lg:self-start">
            <x-slot name="heading">Course Outline</x-slot>

            <div class="academy-progress mb-4" role="progressbar"
                 aria-valuenow="{{ $enrollment->progress_percentage }}" aria-valuemin="0" aria-valuemax="100">
                <div class="academy-progress__bar" style="width: {{ $enrollment->progress_percentage }}%"></div>
            </div>

            <div class="space-y-4">
                @foreach ($enrollment->course?->modules ?? [] as $module)
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            {{ $module->title }}
                        </p>

                        <ul class="mt-2 space-y-1">
                            @foreach ($module->lessons as $moduleLesson)
                                @php($isDone = in_array($moduleLesson->getKey(), $completedIds, true))
                                @php($isCurrent = $lesson?->getKey() === $moduleLesson->getKey())

                                <li>
                                    <button
                                        type="button"
                                        wire:click="selectLesson({{ $moduleLesson->getKey() }})"
                                        @class([
                                            'flex w-full items-start gap-2 rounded-lg px-2 py-1.5 text-left text-sm transition',
                                            'bg-primary-50 font-medium text-primary-700 dark:bg-primary-950 dark:text-primary-300' => $isCurrent,
                                            'text-gray-600 hover:bg-gray-50 dark:text-gray-400 dark:hover:bg-white/5' => ! $isCurrent,
                                        ])
                                    >
                                        <span class="mt-0.5 shrink-0">
                                            @if ($isDone)
                                                <x-filament::icon
                                                    icon="heroicon-s-check-circle"
                                                    class="h-4 w-4 text-success-600"
                                                />
                                            @else
                                                <x-filament::icon
                                                    icon="heroicon-o-play-circle"
                                                    class="h-4 w-4 text-gray-400"
                                                />
                                            @endif
                                        </span>

                                        <span>{{ $moduleLesson->title }}</span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>

            @if ($assessment)
                <div class="mt-6 border-t border-gray-200 pt-4 dark:border-white/10">
                    <x-filament::button
                        tag="a"
                        size="sm"
                        color="warning"
                        class="w-full"
                        :href="\App\Filament\Academy\Pages\TakeQuiz::getUrl([
                            'quiz' => $assessment->getKey(),
                            'enrollment' => $enrollment->getKey(),
                        ], panel: 'academy')"
                    >
                        {{ $assessment->title }}
                    </x-filament::button>
                </div>
            @endif
        </x-filament::section>

        {{-- Lesson body --}}
        <div class="space-y-4">
            @if ($lesson)
                <x-filament::section>
                    <x-slot name="heading">{{ $lesson->title }}</x-slot>

                    @if ($lesson->summary)
                        <x-slot name="description">{{ $lesson->summary }}</x-slot>
                    @endif

                    @if ($lesson->content_type === 'video' && $lesson->video_url)
                        <div class="mb-4 overflow-hidden rounded-xl bg-black">
                            {{-- Native player: no third-party embed, so no
                                 external script runs inside the portal. --}}
                            <video
                                class="aspect-video w-full"
                                controls
                                preload="metadata"
                                src="{{ $lesson->video_url }}"
                            >
                                Your browser does not support embedded video.
                            </video>
                        </div>
                    @endif

                    @if ($lesson->content)
                        <div class="prose prose-sm max-w-none text-gray-700 dark:prose-invert dark:text-gray-300">
                            {!! nl2br(e($lesson->content)) !!}
                        </div>
                    @endif

                    @if ($lesson->documents->isNotEmpty())
                        <div class="mt-6">
                            <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                Material
                            </p>

                            <ul class="space-y-1">
                                @foreach ($lesson->documents as $document)
                                    @continue (! $document->is_downloadable)

                                    <li>
                                        {{--
                                            Routed through the authorising
                                            download controller — these files
                                            live on the private disk and have
                                            no public URL.
                                        --}}
                                        <a
                                            href="{{ route('filament.academy.training.documents.download', ['document' => $document->getKey()]) }}"
                                            class="inline-flex items-center gap-1.5 text-sm text-primary-600 hover:underline dark:text-primary-400"
                                        >
                                            <x-filament::icon icon="heroicon-o-document-arrow-down" class="h-4 w-4" />
                                            {{ $document->title }}
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <x-slot name="footer">
                        <div class="flex flex-wrap items-center gap-3">
                            @if (! in_array($lesson->getKey(), $completedIds, true))
                                <x-filament::button wire:click="completeLesson" icon="heroicon-o-check">
                                    Mark as complete
                                </x-filament::button>
                            @else
                                <x-filament::badge color="success">Completed</x-filament::badge>
                            @endif

                            @if ($quiz)
                                <x-filament::button
                                    tag="a"
                                    color="gray"
                                    :href="\App\Filament\Academy\Pages\TakeQuiz::getUrl([
                                        'quiz' => $quiz->getKey(),
                                        'enrollment' => $enrollment->getKey(),
                                    ], panel: 'academy')"
                                >
                                    Take the quiz
                                </x-filament::button>
                            @endif
                        </div>
                    </x-slot>
                </x-filament::section>
            @else
                <x-filament::section>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        This course has no published lessons yet.
                    </p>
                </x-filament::section>
            @endif
        </div>
    </div>
</x-filament-panels::page>
