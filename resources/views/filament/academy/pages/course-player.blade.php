<x-filament-panels::page class="academy-page">
    @php($lesson = $this->getCurrentLesson())
    @php($completedIds = $this->getCompletedLessonIds())
    @php($quiz = $this->getCurrentQuiz())
    @php($assessment = $this->getFinalAssessment())

    <div class="academy-player">
        {{-- Course outline --}}
        <x-filament::section class="academy-player__outline" icon="heroicon-o-queue-list">
            <x-slot name="heading">Course Outline</x-slot>

            <div class="academy-progress academy-outline__progress" role="progressbar"
                 aria-valuenow="{{ $enrollment->progress_percentage }}" aria-valuemin="0" aria-valuemax="100">
                <div class="academy-progress__bar" style="width: {{ $enrollment->progress_percentage }}%"></div>
            </div>

            <div>
                @foreach ($enrollment->course?->modules ?? [] as $module)
                    <div class="academy-module">
                        <p class="academy-module__title">
                            {{ $module->title }}
                        </p>

                        <ul class="academy-lessons">
                            @foreach ($module->lessons as $moduleLesson)
                                @php($isDone = in_array($moduleLesson->getKey(), $completedIds, true))
                                @php($isCurrent = $lesson?->getKey() === $moduleLesson->getKey())

                                <li>
                                    <button
                                        type="button"
                                        wire:click="selectLesson({{ $moduleLesson->getKey() }})"
                                        @class([
                                            'academy-lesson',
                                            'is-current' => $isCurrent,
                                            'is-done' => $isDone,
                                        ])
                                        @if ($isCurrent) aria-current="step" @endif
                                    >
                                        <span class="academy-lesson__icon">
                                            @if ($isDone)
                                                <x-filament::icon icon="heroicon-s-check-circle" />
                                            @else
                                                <x-filament::icon icon="heroicon-o-play-circle" />
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
                <div class="academy-outline__assessment">
                    <x-filament::button
                        tag="a"
                        size="sm"
                        color="warning"
                        icon="heroicon-m-clipboard-document-check"
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
        <div class="academy-stack">
            @if ($lesson)
                <x-filament::section :icon="$lesson->content_type === 'video' ? 'heroicon-o-film' : 'heroicon-o-book-open'">
                    <x-slot name="heading">{{ $lesson->title }}</x-slot>

                    @if ($lesson->summary)
                        <x-slot name="description">{{ $lesson->summary }}</x-slot>
                    @endif

                    @if ($lesson->content_type === 'video' && $lesson->video_url)
                        <div class="academy-video">
                            {{-- Native player: no third-party embed, so no
                                 external script runs inside the portal. --}}
                            <video
                                controls
                                preload="metadata"
                                src="{{ $lesson->video_url }}"
                            >
                                Your browser does not support embedded video.
                            </video>
                        </div>
                    @endif

                    @if ($lesson->content)
                        <div class="academy-prose">
                            {!! nl2br(e($lesson->content)) !!}
                        </div>
                    @endif

                    @if ($lesson->documents->isNotEmpty())
                        <div class="academy-materials">
                            <p class="academy-eyebrow">
                                Material
                            </p>

                            <ul class="academy-docs">
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
                                            class="academy-doc"
                                        >
                                            <span class="academy-icon-tile"><x-filament::icon icon="heroicon-o-document-arrow-down" /></span>
                                            <span class="academy-doc__title">{{ $document->title }}</span>
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <x-slot name="footer">
                        <div class="academy-actions">
                            @if (! in_array($lesson->getKey(), $completedIds, true))
                                <x-filament::button wire:click="completeLesson" icon="heroicon-o-check">
                                    Mark as complete
                                </x-filament::button>
                            @else
                                <x-filament::badge color="success" icon="heroicon-m-check-circle">Completed</x-filament::badge>
                            @endif

                            @if ($quiz)
                                <x-filament::button
                                    tag="a"
                                    color="gray"
                                    icon="heroicon-m-clipboard-document-check"
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
                <div class="academy-empty">
                    <span class="academy-empty__icon"><x-filament::icon icon="heroicon-o-book-open" /></span>
                    <span>This course has no published lessons yet.</span>
                </div>
            @endif
        </div>
    </div>
</x-filament-panels::page>
