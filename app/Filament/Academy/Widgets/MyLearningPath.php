<?php

namespace App\Filament\Academy\Widgets;

use App\Models\Training\TrainingEnrollment;
use App\Models\Training\TrainingLesson;
use App\Services\Training\TrainingProgressService;
use App\Support\Portal\PortalContext;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * The trainee's course timeline: what is done, what is next, what is
 * still ahead.
 *
 * Reads only enrollments owned by the authenticated user, so the widget
 * has no id parameter to tamper with.
 */
class MyLearningPath extends Widget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.academy.widgets.my-learning-path';

    public static function canView(): bool
    {
        return app(PortalContext::class)->isTrainee();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getCourses(): array
    {
        $user = auth()->user();

        if ($user === null) {
            return [];
        }

        $progress = app(TrainingProgressService::class);

        return TrainingEnrollment::query()
            ->ownedBy($user)
            ->with(['course.modules.lessons'])
            ->get()
            ->map(function (TrainingEnrollment $enrollment) use ($progress): array {
                $completedIds = $enrollment->lessonProgress()
                    ->where('status', 'completed')
                    ->pluck('training_lesson_id')
                    ->all();

                $lessons = $enrollment->course?->modules
                    ->flatMap(fn ($module) => $module->lessons)
                    ->values() ?? collect();

                $next = $progress->nextLesson($enrollment);

                return [
                    'enrollment' => $enrollment,
                    'course' => $enrollment->course,
                    'percentage' => $enrollment->progress_percentage,
                    'completed' => $this->titles($lessons->filter(
                        fn (TrainingLesson $lesson) => in_array($lesson->getKey(), $completedIds, true)
                    )),
                    'current' => $next?->title,
                    'upcoming' => $this->titles($lessons->filter(
                        fn (TrainingLesson $lesson) => ! in_array($lesson->getKey(), $completedIds, true)
                            && $lesson->getKey() !== $next?->getKey()
                    )),
                ];
            })
            ->all();
    }

    /**
     * @param  Collection<int, TrainingLesson>  $lessons
     * @return list<string>
     */
    protected function titles(Collection $lessons): array
    {
        return $lessons->pluck('title')->take(8)->values()->all();
    }
}
