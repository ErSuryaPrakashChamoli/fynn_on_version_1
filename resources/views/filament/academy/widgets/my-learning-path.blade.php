{{--
    The trainee's course timeline. Every value comes from
    MyLearningPath::getCourses(), which reads only enrollments owned by
    the authenticated user.
--}}
<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">My Training</x-slot>
        <x-slot name="description">Where you are in each assigned course.</x-slot>

        @php($courses = $this->getCourses())

        @forelse ($courses as $item)
            @php($course = $item['course'])

            <div @class(['pt-6' => ! $loop->first])>
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h3 class="text-base font-semibold text-gray-950 dark:text-white">
                        {{ $course?->title ?? 'Course' }}
                    </h3>

                    <span class="text-sm font-medium text-gray-500 dark:text-gray-400">
                        {{ $item['percentage'] }}% complete
                    </span>
                </div>

                <div class="academy-progress mt-2" role="progressbar"
                     aria-valuenow="{{ $item['percentage'] }}" aria-valuemin="0" aria-valuemax="100">
                    <div class="academy-progress__bar" style="width: {{ $item['percentage'] }}%"></div>
                </div>

                <div class="academy-timeline mt-4 grid gap-x-8 gap-y-1 sm:grid-cols-3">
                    <div>
                        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            Completed
                        </p>

                        @forelse ($item['completed'] as $title)
                            <div class="academy-step academy-step--done">
                                <span class="academy-step__dot"></span>
                                <span class="text-sm text-gray-700 dark:text-gray-300">{{ $title }}</span>
                            </div>
                        @empty
                            <p class="text-sm text-gray-400">Nothing yet.</p>
                        @endforelse
                    </div>

                    <div>
                        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            In Progress
                        </p>

                        @if ($item['current'])
                            <div class="academy-step academy-step--current">
                                <span class="academy-step__dot"></span>
                                <span class="text-sm font-medium text-gray-900 dark:text-gray-100">
                                    {{ $item['current'] }}
                                </span>
                            </div>
                        @else
                            <p class="text-sm text-gray-400">Course complete.</p>
                        @endif
                    </div>

                    <div>
                        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            Upcoming
                        </p>

                        @forelse ($item['upcoming'] as $title)
                            <div class="academy-step">
                                <span class="academy-step__dot"></span>
                                <span class="text-sm text-gray-500 dark:text-gray-400">{{ $title }}</span>
                            </div>
                        @empty
                            <p class="text-sm text-gray-400">Nothing left.</p>
                        @endforelse
                    </div>
                </div>

                <div class="mt-4">
                    <x-filament::button
                        tag="a"
                        size="sm"
                        :href="\App\Filament\Academy\Pages\MyCourses::getUrl(panel: 'academy')"
                    >
                        Continue learning
                    </x-filament::button>
                </div>
            </div>
        @empty
            <p class="text-sm text-gray-500 dark:text-gray-400">
                No courses have been assigned to you yet. Your trainer will assign them shortly.
            </p>
        @endforelse
    </x-filament::section>
</x-filament-widgets::widget>
