<x-filament-panels::page>
    @php($items = $this->getEnrollments())

    @if (count($items) === 0)
        <x-filament::section>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                No courses have been assigned to you yet.
            </p>
        </x-filament::section>
    @else
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($items as $item)
                @php($enrollment = $item['enrollment'])
                @php($course = $item['course'])

                <x-filament::section>
                    <div class="flex h-full flex-col">
                        <div class="flex items-start justify-between gap-3">
                            <h3 class="text-base font-semibold text-gray-950 dark:text-white">
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
                            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                {{ \Illuminate\Support\Str::limit($course->summary, 120) }}
                            </p>
                        @endif

                        <div class="mt-4">
                            <div class="mb-1 flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                                <span>{{ $item['completedLessons'] }} of {{ $item['totalLessons'] }} lessons</span>
                                <span class="font-semibold">{{ $enrollment->progress_percentage }}%</span>
                            </div>

                            <div class="academy-progress" role="progressbar"
                                 aria-valuenow="{{ $enrollment->progress_percentage }}"
                                 aria-valuemin="0" aria-valuemax="100">
                                <div class="academy-progress__bar"
                                     style="width: {{ $enrollment->progress_percentage }}%"></div>
                            </div>
                        </div>

                        @if ($item['nextLesson'])
                            <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                                Next: <span class="font-medium text-gray-700 dark:text-gray-300">{{ $item['nextLesson']->title }}</span>
                            </p>
                        @endif

                        <div class="mt-auto pt-4">
                            <x-filament::button
                                tag="a"
                                size="sm"
                                :href="\App\Filament\Academy\Pages\CoursePlayer::getUrl(['enrollment' => $enrollment->getKey()], panel: 'academy')"
                            >
                                {{ $enrollment->progress_percentage > 0 ? 'Continue' : 'Start course' }}
                            </x-filament::button>
                        </div>
                    </div>
                </x-filament::section>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
