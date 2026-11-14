{{--
    The trainee's course timeline. Every value comes from
    MyLearningPath::getCourses(), which reads only enrollments owned by
    the authenticated user.
--}}
<x-filament-widgets::widget class="academy-widget">
    <x-filament::section icon="heroicon-o-academic-cap">
        <x-slot name="heading">My Training</x-slot>
        <x-slot name="description">Where you are in each assigned course.</x-slot>

        @php($courses = $this->getCourses())

        @forelse ($courses as $item)
            @php($course = $item['course'])

            <div class="academy-path">
                <div class="academy-path__head">
                    <h3 class="academy-path__title">
                        {{ $course?->title ?? 'Course' }}
                    </h3>

                    <span class="academy-chip academy-chip--accent">
                        {{ $item['percentage'] }}% complete
                    </span>
                </div>

                <div class="academy-progress" role="progressbar"
                     aria-valuenow="{{ $item['percentage'] }}" aria-valuemin="0" aria-valuemax="100">
                    <div class="academy-progress__bar" style="width: {{ $item['percentage'] }}%"></div>
                </div>

                <div class="academy-timeline">
                    <div class="academy-timeline__col">
                        <p class="academy-eyebrow">
                            Completed
                        </p>

                        @forelse ($item['completed'] as $title)
                            <div class="academy-step academy-step--done">
                                <span class="academy-step__dot"></span>
                                <span>{{ $title }}</span>
                            </div>
                        @empty
                            <p class="academy-muted">Nothing yet.</p>
                        @endforelse
                    </div>

                    <div class="academy-timeline__col">
                        <p class="academy-eyebrow">
                            In Progress
                        </p>

                        @if ($item['current'])
                            <div class="academy-step academy-step--current">
                                <span class="academy-step__dot"></span>
                                <span>
                                    {{ $item['current'] }}
                                </span>
                            </div>
                        @else
                            <p class="academy-muted">Course complete.</p>
                        @endif
                    </div>

                    <div class="academy-timeline__col">
                        <p class="academy-eyebrow">
                            Upcoming
                        </p>

                        @forelse ($item['upcoming'] as $title)
                            <div class="academy-step academy-step--upcoming">
                                <span class="academy-step__dot"></span>
                                <span>{{ $title }}</span>
                            </div>
                        @empty
                            <p class="academy-muted">Nothing left.</p>
                        @endforelse
                    </div>
                </div>

                <div class="academy-path__footer">
                    <x-filament::button
                        tag="a"
                        size="sm"
                        icon="heroicon-m-arrow-right"
                        icon-position="after"
                        :href="\App\Filament\Academy\Pages\MyCourses::getUrl(panel: 'academy')"
                    >
                        Continue learning
                    </x-filament::button>
                </div>
            </div>
        @empty
            <div class="academy-empty academy-empty--compact">
                <span class="academy-empty__icon"><x-filament::icon icon="heroicon-o-academic-cap" /></span>
                <span>No courses have been assigned to you yet. Your trainer will assign them shortly.</span>
            </div>
        @endforelse
    </x-filament::section>
</x-filament-widgets::widget>
