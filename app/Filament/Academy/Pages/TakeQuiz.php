<?php

namespace App\Filament\Academy\Pages;

use App\Models\Training\TrainingEnrollment;
use App\Models\Training\TrainingQuiz;
use App\Models\Training\TrainingQuizAttempt;
use App\Models\Training\TrainingQuizQuestion;
use App\Services\Training\QuizGradingService;
use App\Support\Portal\PortalContext;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The quiz/assessment runner.
 *
 * Three separate checks stand between a trainee and someone else's
 * quiz data:
 *
 *  - the enrollment must be theirs (policy check in mount);
 *  - the quiz must belong to that enrollment's course (assertQuizBelongs);
 *  - a submitted attempt must be theirs and still open (policy 'submit').
 *
 * Grading is done server-side by QuizGradingService against
 * correct_answer columns that the question model hides from
 * serialization, so the Livewire payload sent to the browser never
 * contains the answer key.
 */
class TakeQuiz extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'quiz/{quiz}/{enrollment}';

    protected static ?string $title = 'Quiz';

    protected string $view = 'filament.academy.pages.take-quiz';

    public TrainingQuiz $quiz;

    public TrainingEnrollment $enrollment;

    public ?TrainingQuizAttempt $attempt = null;

    /**
     * Answers keyed by question id.
     *
     * @var array<int, string>
     */
    public array $answers = [];

    public bool $showResult = false;

    public static function canAccess(): bool
    {
        return app(PortalContext::class)->isTrainee();
    }

    /**
     * Both records arrive already resolved by implicit binding, so an
     * unknown id is a 404 before this runs. The order below is
     * deliberate: authorise the ENROLLMENT first, so probing quiz ids
     * against someone else's enrollment is refused without revealing
     * anything about the quiz.
     */
    public function mount(TrainingQuiz $quiz, TrainingEnrollment $enrollment): void
    {
        abort_unless(auth()->user()?->can('view', $enrollment), 403);

        $this->assertQuizBelongs($quiz, $enrollment);

        $this->quiz = $quiz->load('questions');
        $this->enrollment = $enrollment;
    }

    public function getTitle(): string
    {
        return $this->quiz->title;
    }

    public function getSubheading(): ?string
    {
        $remaining = app(QuizGradingService::class)->attemptsRemaining($this->quiz, $this->enrollment);

        return "Pass mark {$this->quiz->pass_percentage}% · "
            .($remaining === PHP_INT_MAX ? 'Unlimited attempts' : "{$remaining} attempt(s) left");
    }

    public function startAttempt(): void
    {
        abort_unless(auth()->user()?->can('create', TrainingQuizAttempt::class), 403);

        try {
            $this->attempt = app(QuizGradingService::class)
                ->start($this->quiz, $this->enrollment, auth()->user());
        } catch (ValidationException $exception) {
            Notification::make()
                ->title('No attempts left')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->answers = [];
        $this->showResult = false;
    }

    public function submit(): void
    {
        if ($this->attempt === null) {
            return;
        }

        abort_unless(auth()->user()?->can('submit', $this->attempt), 403);

        $this->attempt = app(QuizGradingService::class)->grade($this->attempt, $this->answers);
        $this->showResult = true;

        Notification::make()
            ->title($this->attempt->passed ? 'Passed' : 'Not passed')
            ->body("You scored {$this->attempt->percentage}%.")
            ->color($this->attempt->passed ? 'success' : 'danger')
            ->send();
    }

    /**
     * @return Collection<int, TrainingQuizQuestion>
     */
    public function getQuestions(): Collection
    {
        return $this->quiz->questions()->get();
    }

    /**
     * The trainee's own past attempts on this quiz — never anyone
     * else's, because the scope is their own enrollment.
     *
     * @return Collection<int, TrainingQuizAttempt>
     */
    public function getHistory(): Collection
    {
        return TrainingQuizAttempt::query()
            ->where('training_quiz_id', $this->quiz->getKey())
            ->where('training_enrollment_id', $this->enrollment->getKey())
            ->where('status', 'completed')
            ->latest('completed_at')
            ->get();
    }

    /**
     * A quiz reached through an enrollment for a different course is
     * treated as not found, so pairing ids from two courses reveals
     * nothing about whether either exists.
     */
    protected function assertQuizBelongs(TrainingQuiz $quiz, TrainingEnrollment $enrollment): void
    {
        $course = $quiz->resolveCourse();

        abort_if(
            $course === null || $course->getKey() !== $enrollment->training_course_id,
            404
        );
    }
}
