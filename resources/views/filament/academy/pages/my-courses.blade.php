<x-filament-panels::page class="academy-page">
    @php($items = $this->getEnrollments())

    @if (count($items) === 0)
        <div class="academy-empty">
            <span class="academy-empty__icon"><x-filament::icon icon="heroicon-o-academic-cap" /></span>
            <span>No courses have been assigned to you yet.</span>
        </div>
    @else
        <div class="academy-grid">
            @foreach ($items as $item)
                @php($enrollment = $item['enrollment'])
                @php($course = $item['course'])

                <article class="academy-card">
                    <div class="academy-course__head">
                        <span class="academy-icon-tile"><x-filament::icon icon="heroicon-o-book-open" /></span>

                        <h3 class="academy-course__title">
                            {{ $course?->title ?? 'Course' }}
                        </h3>

                        <x-filament::badge :color="match ($enrollment->status) {
                            'completed' => 'success',
                            'in_progress' => 'warning',
                            default => 'gray',
                        }">
                            {{ str($enrollment->status)->headline() }}
                        </x-filament::badge>
                    </div>

                    @if ($course?->summary)
                        <p class="academy-course__summary">
                            {{ \Illuminate\Support\Str::limit($course->summary, 120) }}
                        </p>
                    @endif

                    <div class="academy-course__progress">
                        <div class="academy-progress-meta">
                            <span>{{ $item['completedLessons'] }} of {{ $item['totalLessons'] }} lessons</span>
                            <span class="academy-progress-meta__value">{{ $enrollment->progress_percentage }}%</span>
                        </div>

                        <div class="academy-progress" role="progressbar"
                             aria-valuenow="{{ $enrollment->progress_percentage }}"
                             aria-valuemin="0" aria-valuemax="100">
                            <div class="academy-progress__bar"
                                 style="width: {{ $enrollment->progress_percentage }}%"></div>
                        </div>
                    </div>

                    @if ($item['nextLesson'])
                        <p class="academy-course__next">
                            <x-filament::icon icon="heroicon-m-play-circle" />
                            <span>Next: <strong>{{ $item['nextLesson']->title }}</strong></span>
                        </p>
                    @endif

                    <div class="academy-course__footer">
                        <x-filament::button
                            tag="a"
                            size="sm"
                            icon="heroicon-m-arrow-right"
                            icon-position="after"
                            :href="\App\Filament\Academy\Pages\CoursePlayer::getUrl(['enrollment' => $enrollment->getKey()], panel: 'academy')"
                        >
                            {{ $enrollment->progress_percentage > 0 ? 'Continue' : 'Start course' }}
                        </x-filament::button>
                    </div>
                </article>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
