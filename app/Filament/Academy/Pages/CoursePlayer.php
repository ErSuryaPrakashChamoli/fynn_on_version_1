<?php

namespace App\Filament\Academy\Pages;

use App\Models\Training\TrainingEnrollment;
use App\Models\Training\TrainingLesson;
use App\Models\Training\TrainingQuiz;
use App\Services\Training\TrainingProgressService;
use App\Support\Portal\PortalContext;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * The lesson viewer.
 *
 * The route carries an enrollment id, which makes this the Academy's
 * main IDOR surface — so mount() authorises the record through
 * TrainingEnrollmentPolicy BEFORE loading anything, and the lesson
 * selector only ever accepts lessons that belong to that enrollment's
 * own course (see resolveLesson). Changing the id in the URL to another
 * trainee's enrollment produces a 403, and pairing your own enrollment
 * with someone else's lesson id produces a 404.
 */
class CoursePlayer extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'my-courses/{enrollment}';

    protected static ?string $title = 'Course';

    protected string $view = 'filament.academy.pages.course-player';

    public TrainingEnrollment $enrollment;

    public ?int $lessonId = null;

    public static function canAccess(): bool
    {
        return app(PortalContext::class)->isTrainee();
    }

    /**
     * Laravel's implicit binding has already resolved {enrollment} by
     * the time Livewire calls this, so a bad id is a 404 before any of
     * our code runs; the policy check below is what turns "someone
     * else's enrollment" into a 403.
     */
    public function mount(TrainingEnrollment $enrollment): void
    {
        abort_unless(auth()->user()?->can('view', $enrollment), 403);

        $this->enrollment = $enrollment->load(['course.modules.lessons', 'batch']);
        $this->lessonId = $this->defaultLessonId();
    }

    public function getTitle(): string
    {
        return $this->enrollment->course?->title ?? 'Course';
    }

    public function getSubheading(): ?string
    {
        return $this->enrollment->progress_percentage.'% complete';
    }

    /**
     * Only lessons inside this enrollment's course are selectable —
     * anything else is treated as not found.
     */
    public function selectLesson(int $lessonId): void
    {
        $lesson = $this->resolveLesson($lessonId);

        abort_if($lesson === null, 404);

        $this->lessonId = $lesson->getKey();

        app(TrainingProgressService::class)->startLesson($this->enrollment, $lesson);
    }

    public function completeLesson(): void
    {
        $lesson = $this->resolveLesson($this->lessonId);

        abort_if($lesson === null, 404);
        abort_unless(auth()->user()?->can('recordProgress', $this->enrollment), 403);

        app(TrainingProgressService::class)->completeLesson($this->enrollment, $lesson);

        $this->enrollment->refresh();
        $this->lessonId = app(TrainingProgressService::class)
            ->nextLesson($this->enrollment)?->getKey() ?? $this->lessonId;

        Notification::make()
            ->title('Lesson completed')
            ->body("You are now {$this->enrollment->progress_percentage}% through this course.")
            ->success()
            ->send();
    }

    public function getCurrentLesson(): ?TrainingLesson
    {
        return $this->resolveLesson($this->lessonId);
    }

    /**
     * @return list<int>
     */
    public function getCompletedLessonIds(): array
    {
        return $this->enrollment->lessonProgress()
            ->where('status', 'completed')
            ->pluck('training_lesson_id')
            ->all();
    }

    /**
     * The quiz attached to the lesson being viewed, if any.
     */
    public function getCurrentQuiz(): ?TrainingQuiz
    {
        return $this->getCurrentLesson()
            ?->quizzes()
            ->where('status', 'published')
            ->first();
    }

    public function getFinalAssessment(): ?TrainingQuiz
    {
        return $this->enrollment->course
            ?->assessments()
            ->where('kind', TrainingQuiz::KIND_ASSESSMENT)
            ->where('status', 'published')
            ->first();
    }

    protected function defaultLessonId(): ?int
    {
        $next = app(TrainingProgressService::class)->nextLesson($this->enrollment);

        if ($next !== null) {
            return $next->getKey();
        }

        return $this->enrollment->course?->modules
            ->flatMap->lessons
            ->first()
            ?->getKey();
    }

    protected function resolveLesson(?int $lessonId): ?TrainingLesson
    {
        if ($lessonId === null) {
            return null;
        }

        return TrainingLesson::query()
            ->whereKey($lessonId)
            ->whereHas(
                'module',
                fn ($query) => $query->where('training_course_id', $this->enrollment->training_course_id)
            )
            ->with('documents')
            ->first();
    }
}
