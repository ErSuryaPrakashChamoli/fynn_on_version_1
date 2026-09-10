<?php

namespace Tests\Feature\Portal;

use App\Enums\PortalRole;
use App\Models\Training\TrainingCertificate;
use App\Models\Training\TrainingCourse;
use App\Models\Training\TrainingEnrollment;
use App\Models\Training\TrainingLesson;
use App\Models\Training\TrainingModule;
use App\Models\Training\TrainingQuiz;
use App\Models\Training\TrainingQuizQuestion;
use App\Models\User;
use App\Services\Training\CertificateService;
use App\Services\Training\QuizGradingService;
use App\Services\Training\TrainingProgressService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The training module actually working: progress rolls up, quizzes grade
 * correctly, attempt caps hold, and certificates are only issued when
 * the course is genuinely complete.
 */
class TrainingWorkflowTest extends PortalBoundaryTestCase
{
    private User $trainee;

    private User $trainer;

    private TrainingCourse $course;

    private TrainingEnrollment $enrollment;

    /** @var Collection<int, TrainingLesson> */
    private $lessons;

    protected function setUp(): void
    {
        parent::setUp();

        $this->trainee = $this->makePortalUser(PortalRole::Trainee);
        $this->trainer = $this->makePortalUser(PortalRole::Trainer);

        $this->course = TrainingCourse::factory()->create(['tenant_id' => $this->production->id]);

        $module = TrainingModule::factory()->create([
            'training_course_id' => $this->course->id,
            'sort_order' => 1,
        ]);

        $this->lessons = TrainingLesson::factory()
            ->count(4)
            ->sequence(
                ['sort_order' => 1],
                ['sort_order' => 2],
                ['sort_order' => 3],
                ['sort_order' => 4],
            )
            ->create(['training_module_id' => $module->id]);

        $this->enrollment = TrainingEnrollment::factory()->assigned()->create([
            'tenant_id' => $this->production->id,
            'training_course_id' => $this->course->id,
            'trainee_id' => $this->trainee->id,
        ]);
    }

    public function test_completing_lessons_rolls_progress_up_to_the_enrollment(): void
    {
        $progress = app(TrainingProgressService::class);

        $progress->completeLesson($this->enrollment, $this->lessons[0]);
        $this->assertSame(25, $this->enrollment->refresh()->progress_percentage);
        $this->assertSame('in_progress', $this->enrollment->status);

        $progress->completeLesson($this->enrollment, $this->lessons[1]);
        $this->assertSame(50, $this->enrollment->refresh()->progress_percentage);
    }

    public function test_completing_every_lesson_marks_the_enrollment_complete(): void
    {
        $progress = app(TrainingProgressService::class);

        foreach ($this->lessons as $lesson) {
            $progress->completeLesson($this->enrollment, $lesson);
        }

        $this->enrollment->refresh();

        $this->assertSame(100, $this->enrollment->progress_percentage);
        $this->assertSame('completed', $this->enrollment->status);
        $this->assertNotNull($this->enrollment->completed_at);
    }

    public function test_completing_a_lesson_twice_does_not_double_count_it(): void
    {
        $progress = app(TrainingProgressService::class);

        $progress->completeLesson($this->enrollment, $this->lessons[0]);
        $progress->completeLesson($this->enrollment, $this->lessons[0]);

        $this->assertSame(25, $this->enrollment->refresh()->progress_percentage);
        $this->assertSame(1, $this->enrollment->lessonProgress()->count());
    }

    public function test_the_next_lesson_is_the_first_uncompleted_one_in_course_order(): void
    {
        $progress = app(TrainingProgressService::class);

        $this->assertSame($this->lessons[0]->id, $progress->nextLesson($this->enrollment)?->id);

        $progress->completeLesson($this->enrollment, $this->lessons[0]);

        $this->assertSame($this->lessons[1]->id, $progress->nextLesson($this->enrollment)?->id);
    }

    public function test_a_quiz_is_graded_against_the_stored_answer_key(): void
    {
        $quiz = $this->makeQuiz(passPercentage: 60);
        $questions = $quiz->questions()->get();

        $grading = app(QuizGradingService::class);
        $attempt = $grading->start($quiz, $this->enrollment, $this->trainee);

        // Three of four correct — 'B' is the seeded correct key.
        $answers = [
            $questions[0]->id => 'B',
            $questions[1]->id => 'B',
            $questions[2]->id => 'B',
            $questions[3]->id => 'A',
        ];

        $graded = $grading->grade($attempt, $answers);

        $this->assertSame(3, $graded->score);
        $this->assertSame(4, $graded->total_marks);
        $this->assertSame(75, $graded->percentage);
        $this->assertTrue($graded->passed);
        $this->assertSame('completed', $graded->status);
        $this->assertSame(4, $graded->answers()->count());
    }

    public function test_a_failing_score_is_recorded_as_failed(): void
    {
        $quiz = $this->makeQuiz(passPercentage: 80);
        $questions = $quiz->questions()->get();

        $grading = app(QuizGradingService::class);
        $attempt = $grading->start($quiz, $this->enrollment, $this->trainee);

        $graded = $grading->grade($attempt, [
            $questions[0]->id => 'B',
            $questions[1]->id => 'A',
            $questions[2]->id => 'C',
            $questions[3]->id => 'D',
        ]);

        $this->assertSame(25, $graded->percentage);
        $this->assertFalse($graded->passed);
    }

    public function test_unanswered_questions_score_zero_rather_than_erroring(): void
    {
        $quiz = $this->makeQuiz();
        $grading = app(QuizGradingService::class);
        $attempt = $grading->start($quiz, $this->enrollment, $this->trainee);

        $graded = $grading->grade($attempt, []);

        $this->assertSame(0, $graded->score);
        $this->assertSame(0, $graded->percentage);
        $this->assertFalse($graded->passed);
    }

    public function test_the_attempt_cap_is_enforced(): void
    {
        $quiz = $this->makeQuiz(maxAttempts: 2);
        $grading = app(QuizGradingService::class);

        $grading->grade($grading->start($quiz, $this->enrollment, $this->trainee), []);
        $grading->grade($grading->start($quiz, $this->enrollment, $this->trainee), []);

        $this->assertSame(0, $grading->attemptsRemaining($quiz, $this->enrollment));

        $this->expectException(ValidationException::class);

        $grading->start($quiz, $this->enrollment, $this->trainee);
    }

    public function test_a_certificate_is_refused_until_the_course_is_complete(): void
    {
        app(TrainingProgressService::class)->completeLesson($this->enrollment, $this->lessons[0]);

        $this->expectException(ValidationException::class);

        app(CertificateService::class)->issue($this->enrollment->refresh(), $this->trainer);
    }

    public function test_a_certificate_is_issued_once_the_course_is_complete(): void
    {
        $this->completeEverything();

        $certificate = app(CertificateService::class)->issue($this->enrollment->refresh(), $this->trainer);

        $this->assertSame($this->trainee->id, $certificate->trainee_id);
        $this->assertSame($this->production->id, $certificate->tenant_id);
        $this->assertMatchesRegularExpression(
            '/^FE-TRN-\d{4}-\d{5}$/',
            $certificate->certificate_number,
        );
    }

    public function test_issuing_twice_returns_the_same_certificate(): void
    {
        $this->completeEverything();

        $service = app(CertificateService::class);

        $first = $service->issue($this->enrollment->refresh(), $this->trainer);
        $second = $service->issue($this->enrollment->refresh(), $this->trainer);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, TrainingCertificate::count());
    }

    public function test_certificate_numbers_do_not_collide(): void
    {
        $this->completeEverything();

        $service = app(CertificateService::class);
        $service->issue($this->enrollment->refresh(), $this->trainer);

        $otherTrainee = $this->makePortalUser(PortalRole::Trainee);
        $otherEnrollment = TrainingEnrollment::factory()->create([
            'tenant_id' => $this->production->id,
            'training_course_id' => $this->course->id,
            'trainee_id' => $otherTrainee->id,
        ]);

        foreach ($this->lessons as $lesson) {
            app(TrainingProgressService::class)->completeLesson($otherEnrollment, $lesson);
        }

        $service->issue($otherEnrollment->refresh(), $this->trainer);

        $this->assertSame(2, TrainingCertificate::distinct('certificate_number')->count('certificate_number'));
    }

    /**
     * Certificate issue is a trainer action, and the audit trail has to
     * show who did it.
     */
    public function test_issuing_a_certificate_writes_an_audit_entry(): void
    {
        $this->completeEverything();

        $this->actingAsPortalUser($this->trainer);

        app(CertificateService::class)->issue($this->enrollment->refresh(), $this->trainer);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'portal',
            'description' => 'Certificate issued',
            'causer_id' => $this->trainer->id,
        ]);
    }

    private function completeEverything(): void
    {
        foreach ($this->lessons as $lesson) {
            app(TrainingProgressService::class)->completeLesson($this->enrollment, $lesson);
        }
    }

    private function makeQuiz(int $passPercentage = 60, int $maxAttempts = 3): TrainingQuiz
    {
        $quiz = TrainingQuiz::factory()->create([
            'quizzable_type' => TrainingLesson::class,
            'quizzable_id' => $this->lessons[0]->id,
            'pass_percentage' => $passPercentage,
            'max_attempts' => $maxAttempts,
        ]);

        TrainingQuizQuestion::factory()->count(4)->create([
            'training_quiz_id' => $quiz->id,
            'marks' => 1,
        ]);

        return $quiz;
    }
}
