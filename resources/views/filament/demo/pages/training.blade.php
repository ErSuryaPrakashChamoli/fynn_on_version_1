<x-filament-panels::page>
    @php($courses = $this->getCourses())

    @if ($courses->isEmpty())
        <x-filament::section>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                No training programmes have been configured in this sandbox.
            </p>
        </x-filament::section>
    @else
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            @foreach ($courses as $course)
                <x-filament::section>
                    <div class="flex h-full flex-col">
                        <div class="flex items-start justify-between gap-3">
                            <h3 class="text-base font-semibold text-gray-950 dark:text-white">
                                {{ $course->title }}
                            </h3>

                            <x-filament::badge color="info">
                                {{ str($course->level)->headline() }}
                            </x-filament::badge>
                        </div>

                        @if ($course->summary)
                            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                {{ \Illuminate\Support\Str::limit($course->summary, 140) }}
                            </p>
                        @endif

                        <dl class="mt-auto grid grid-cols-3 gap-3 pt-5 text-center">
                            <div>
                                <dt class="text-xs uppercase tracking-wide text-gray-400">Modules</dt>
                                <dd class="text-lg font-semibold text-gray-950 dark:text-white">{{ $course->modules_count }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs uppercase tracking-wide text-gray-400">Enrolled</dt>
                                <dd class="text-lg font-semibold text-gray-950 dark:text-white">{{ $course->enrollments_count }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs uppercase tracking-wide text-gray-400">Duration</dt>
                                <dd class="text-lg font-semibold text-gray-950 dark:text-white">
                                    {{ round($course->duration_minutes / 60) }}h
                                </dd>
                            </div>
                        </dl>
                    </div>
                </x-filament::section>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
