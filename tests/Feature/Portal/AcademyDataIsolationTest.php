<?php

namespace Tests\Feature\Portal;

use App\Enums\PortalRole;
use App\Models\Tenant;
use App\Models\Training\TrainingCourse;
use App\Models\Training\TrainingEnrollment;
use App\Models\Training\TrainingLesson;
use App\Models\Training\TrainingLessonDocument;
use App\Models\Training\TrainingModule;
use App\Models\Training\TrainingQuiz;
use App\Models\Training\TrainingQuizAttempt;
use App\Models\Training\TrainingQuizQuestion;
use App\Models\Training\TrainingRemark;
use App\Models\User;

/**
 * IDOR and tenant isolation inside the Academy.
 *
 * Everything a trainee can read hangs off an enrollment, so these tests
 * take the enrollment id of one trainee and try to use it as another —
 * the exact attack the brief calls out.
 */
class AcademyDataIsolationTest extends PortalBoundaryTestCase
{
    private User $traineeA;

    private User $traineeB;

    private TrainingEnrollment $enrollmentA;

    private TrainingEnrollment $enrollmentB;

    private TrainingCourse $course;

    protected function setUp(): void
    {
        parent::setUp();

        $this->traineeA = $this->makePortalUser(PortalRole::Trainee);
        $this->traineeB = $this->makePortalUser(PortalRole::Trainee);

        $this->course = TrainingCourse::factory()->create(['tenant_id' => $this->production->id]);

        $module = TrainingModule::factory()->create(['training_course_id' => $this->course->id]);
        TrainingLesson::factory()->count(2)->create(['training_module_id' => $module->id]);

        $this->enrollmentA = $this->enrollFor($this->traineeA);
        $this->enrollmentB = $this->enrollFor($this->traineeB);
    }

    private function enrollFor(User $trainee): TrainingEnrollment
    {
        return TrainingEnrollment::factory()->create([
            'tenant_id' => $this->production->id,
            'training_course_id' => $this->course->id,
            'trainee_id' => $trainee->id,
        ]);
    }

    public function test_a_trainee_can_open_their_own_course(): void
    {
        $this->actingAsPortalUser($this->traineeA);

        $this->get("/academy/my-courses/{$this->enrollmentA->id}")->assertOk();
    }

    public function test_a_trainee_cannot_open_another_trainees_enrollment(): void
    {
        $this->actingAsPortalUser($this->traineeA);

        $this->get("/academy/my-courses/{$this->enrollmentB->id}")->assertForbidden();
    }

    public function test_a_trainee_cannot_take_a_quiz_through_another_trainees_enrollment(): void
    {
        $lesson = $this->course->modules->first()->lessons->first();

        $quiz = TrainingQuiz::factory()->create([
            'quizzable_type' => TrainingLesson::class,
            'quizzable_id' => $lesson->id,
        ]);

        $this->actingAsPortalUser($this->traineeA);

        $this->get("/academy/quiz/{$quiz->id}/{$this->enrollmentB->id}")->assertForbidden();
    }

    /**
     * Pairing your own enrollment with a quiz from a different course is
     * a 404 rather than a 403, so probing ids reveals nothing about
     * whether the quiz exists.
     */
    public function test_a_quiz_from_another_course_is_not_found_for_your_enrollment(): void
    {
        $otherCourse = TrainingCourse::factory()->create(['tenant_id' => $this->production->id]);
        $otherModule = TrainingModule::factory()->create(['training_course_id' => $otherCourse->id]);
        $otherLesson = TrainingLesson::factory()->create(['training_module_id' => $otherModule->id]);

        $foreignQuiz = TrainingQuiz::factory()->create([
            'quizzable_type' => TrainingLesson::class,
            'quizzable_id' => $otherLesson->id,
        ]);

        $this->actingAsPortalUser($this->traineeA);

        $this->get("/academy/quiz/{$foreignQuiz->id}/{$this->enrollmentA->id}")->assertNotFound();
    }

    public function test_the_enrollment_policy_refuses_another_trainees_record(): void
    {
        $this->actingAsPortalUser($this->traineeA);

        $this->assertTrue($this->traineeA->can('view', $this->enrollmentA));
        $this->assertFalse($this->traineeA->can('view', $this->enrollmentB));
        $this->assertFalse($this->traineeA->can('recordProgress', $this->enrollmentB));
    }

    public function test_a_trainee_cannot_read_another_trainees_quiz_attempt(): void
    {
        $lesson = $this->course->modules->first()->lessons->first();
        $quiz = TrainingQuiz::factory()->create([
            'quizzable_type' => TrainingLesson::class,
            'quizzable_id' => $lesson->id,
        ]);

        $attemptB = TrainingQuizAttempt::factory()->create([
            'training_quiz_id' => $quiz->id,
            'training_enrollment_id' => $this->enrollmentB->id,
            'trainee_id' => $this->traineeB->id,
        ]);

        $this->actingAsPortalUser($this->traineeA);

        $this->assertFalse($this->traineeA->can('view', $attemptB));
        $this->assertFalse($this->traineeA->can('submit', $attemptB));
    }

    /**
     * A trainer's internal note is not feedback — the trainee must not
     * be able to read it even though the remark is about them.
     */
    public function test_a_trainee_cannot_read_a_remark_the_trainer_kept_internal(): void
    {
        $trainer = $this->makePortalUser(PortalRole::Trainer);

        $visible = TrainingRemark::factory()->create([
            'training_enrollment_id' => $this->enrollmentA->id,
            'trainer_id' => $trainer->id,
            'is_visible_to_trainee' => true,
        ]);

        $internal = TrainingRemark::factory()->internal()->create([
            'training_enrollment_id' => $this->enrollmentA->id,
            'trainer_id' => $trainer->id,
        ]);

        $this->actingAsPortalUser($this->traineeA);

        $this->assertTrue($this->traineeA->can('view', $visible));
        $this->assertFalse($this->traineeA->can('view', $internal));
    }

    public function test_a_trainee_cannot_reach_any_trainer_resource(): void
    {
        $this->actingAsPortalUser($this->traineeA);

        foreach ([
            '/academy/courses',
            '/academy/modules',
            '/academy/lessons',
            '/academy/batches',
            '/academy/trainees',
            '/academy/assessments',
            '/academy/attendance',
            '/academy/certificates',
        ] as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    public function test_a_trainer_from_another_tenant_cannot_touch_this_tenants_course(): void
    {
        $otherTenant = Tenant::factory()->create(['slug' => 'other-co']);
        $foreignTrainer = $this->makePortalUser(PortalRole::Trainer, $otherTenant);

        $this->actingAsPortalUser($foreignTrainer);

        $this->assertFalse($foreignTrainer->can('view', $this->course));
        $this->assertFalse($foreignTrainer->can('update', $this->course));
    }

    /**
     * Documents live on the private disk and are only reachable through
     * the authorising controller.
     */
    public function test_a_document_is_not_downloadable_without_an_enrollment(): void
    {
        $lesson = $this->course->modules->first()->lessons->first();

        $document = TrainingLessonDocument::factory()->create([
            'training_lesson_id' => $lesson->id,
        ]);

        $outsider = $this->makePortalUser(PortalRole::Trainee);
        $this->actingAsPortalUser($outsider);

        $this->get("/academy/training/documents/{$document->id}")->assertNotFound();
    }

    public function test_a_document_from_another_tenant_is_not_downloadable(): void
    {
        $otherTenant = Tenant::factory()->create(['slug' => 'other-co-docs']);
        $foreignCourse = TrainingCourse::factory()->create(['tenant_id' => $otherTenant->id]);
        $foreignModule = TrainingModule::factory()->create(['training_course_id' => $foreignCourse->id]);
        $foreignLesson = TrainingLesson::factory()->create(['training_module_id' => $foreignModule->id]);
        $foreignDocument = TrainingLessonDocument::factory()->create([
            'training_lesson_id' => $foreignLesson->id,
        ]);

        $this->actingAsPortalUser($this->makePortalUser(PortalRole::Trainer));

        $this->get("/academy/training/documents/{$foreignDocument->id}")->assertNotFound();
    }

    /**
     * The answer key must never be serialized to the browser — the quiz
     * screen renders question models straight into Livewire state.
     */
    public function test_the_answer_key_is_hidden_from_question_serialization(): void
    {
        $lesson = $this->course->modules->first()->lessons->first();
        $quiz = TrainingQuiz::factory()->create([
            'quizzable_type' => TrainingLesson::class,
            'quizzable_id' => $lesson->id,
        ]);

        $question = TrainingQuizQuestion::factory()->create([
            'training_quiz_id' => $quiz->id,
        ]);

        $serialized = $question->toArray();

        $this->assertArrayNotHasKey('correct_answer', $serialized);
        $this->assertArrayNotHasKey('explanation', $serialized);
    }
}
