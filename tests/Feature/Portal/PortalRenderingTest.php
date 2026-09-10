<?php

namespace Tests\Feature\Portal;

use App\Enums\PortalRole;
use App\Filament\Academy\Pages\CoursePlayer;
use App\Filament\Academy\Pages\TakeQuiz;
use App\Filament\Academy\Widgets\BatchProgressTable;
use App\Filament\Academy\Widgets\MyLearningPath;
use App\Filament\Academy\Widgets\TraineeStatsOverview;
use App\Filament\Academy\Widgets\TrainerStatsOverview;
use App\Filament\Demo\Widgets\DisbursalTrendChart;
use App\Filament\Demo\Widgets\LeadFunnelChart;
use App\Filament\Demo\Widgets\LeadTrendChart;
use App\Filament\Demo\Widgets\PipelineStats;
use App\Filament\Demo\Widgets\ProductMixChart;
use App\Filament\Demo\Widgets\TeamPerformanceChart;
use App\Models\Training\TrainingCourse;
use App\Models\Training\TrainingEnrollment;
use App\Models\Training\TrainingLesson;
use App\Models\Training\TrainingQuiz;
use App\Models\User;
use App\Services\Training\TrainingProgressService;
use Database\Seeders\Demo\DemoDataSeeder;
use Database\Seeders\Training\TrainingContentSeeder;
use Livewire\Livewire;

/**
 * The screens actually render, with the seeded content on them.
 *
 * The isolation tests prove who is refused; these prove the pages a
 * permitted user reaches are not blank or broken — which is the other
 * half of "it works".
 */
class PortalRenderingTest extends PortalBoundaryTestCase
{
    public function test_the_trainer_dashboard_renders_its_widgets(): void
    {
        $this->seedAcademy();

        $this->actingAsPortalUser($this->makePortalUser(PortalRole::Trainer));

        $this->get('/academy')
            ->assertOk()
            ->assertSee('Trainer Dashboard');

        // Dashboard widgets are separate, lazily hydrated Livewire
        // components — their content is not in the page's first
        // response, so they are asserted through the component.
        Livewire::test(TrainerStatsOverview::class)
            ->assertSee('Active Batches')
            ->assertSee('Trainees')
            ->assertSee('Average Progress')
            ->assertSee('Average Score');

        Livewire::test(BatchProgressTable::class)
            ->assertSee('Trainee Progress');
    }

    public function test_the_trainer_navigation_exposes_the_delivery_and_content_areas(): void
    {
        $this->actingAsPortalUser($this->makePortalUser(PortalRole::Trainer));

        $response = $this->get('/academy');

        $response->assertOk();

        foreach (['Courses', 'Modules', 'Lessons', 'Batches', 'Trainees', 'Attendance', 'Certificates'] as $item) {
            $response->assertSee($item);
        }
    }

    public function test_the_trainee_dashboard_greets_them_and_shows_their_own_progress(): void
    {
        [$trainee, $enrollment] = $this->seedTraineeWithProgress();

        $this->actingAsPortalUser($trainee);

        $this->get('/academy')
            ->assertOk()
            ->assertSee('Welcome, '.$trainee->name);

        Livewire::test(TraineeStatsOverview::class)
            ->assertSee('Overall Progress')
            ->assertSee('Assigned Courses')
            ->assertSee('Certificates');

        Livewire::test(MyLearningPath::class)
            ->assertSee('My Training')
            ->assertSee('Completed')
            ->assertSee('In Progress');
    }

    /**
     * The trainee navigation must not advertise a single trainer screen.
     */
    public function test_the_trainee_navigation_hides_every_trainer_area(): void
    {
        [$trainee] = $this->seedTraineeWithProgress();

        $this->actingAsPortalUser($trainee);

        $response = $this->get('/academy');

        $response->assertOk();
        $response->assertSee('My Courses');
        $response->assertSee('My Certificates');
        $response->assertDontSee('/academy/batches');
        $response->assertDontSee('/academy/trainees');
        $response->assertDontSee('/academy/attendance');
    }

    public function test_the_course_player_renders_the_lesson_and_its_outline(): void
    {
        [$trainee, $enrollment] = $this->seedTraineeWithProgress();

        $this->actingAsPortalUser($trainee);

        $this->get("/academy/my-courses/{$enrollment->id}")
            ->assertOk()
            ->assertSee('Course Outline')
            ->assertSee('Company Introduction')
            ->assertSee('Mark as complete');
    }

    public function test_a_trainee_can_complete_a_lesson_through_the_page(): void
    {
        [$trainee, $enrollment] = $this->seedTraineeWithProgress();

        $this->actingAsPortalUser($trainee);

        $before = $enrollment->refresh()->progress_percentage;

        Livewire::test(CoursePlayer::class, ['enrollment' => $enrollment])
            ->call('completeLesson')
            ->assertHasNoErrors();

        $this->assertGreaterThan($before, $enrollment->refresh()->progress_percentage);
    }

    public function test_a_trainee_can_start_and_submit_a_quiz_through_the_page(): void
    {
        [$trainee, $enrollment] = $this->seedTraineeWithProgress();

        $quiz = TrainingQuiz::query()
            ->where('kind', TrainingQuiz::KIND_QUIZ)
            ->where('quizzable_type', TrainingLesson::class)
            ->firstOrFail();

        $this->actingAsPortalUser($trainee);

        $component = Livewire::test(TakeQuiz::class, [
            'quiz' => $quiz,
            'enrollment' => $enrollment,
        ])->call('startAttempt');

        $attempt = $component->get('attempt');
        $this->assertNotNull($attempt);
        $this->assertSame('in_progress', $attempt->status);

        // Answer each question with its own correct key — the seeded
        // quiz deliberately does not use the same letter throughout.
        $answers = $quiz->questions()->get()
            ->mapWithKeys(fn ($question): array => [
                $question->getKey() => $question->correct_answer[0],
            ])
            ->all();

        $component->set('answers', $answers)->call('submit');

        $graded = $component->get('attempt');

        $this->assertSame('completed', $graded->status);
        $this->assertSame(100, $graded->percentage);
        $this->assertTrue($graded->passed);
        $this->assertSame($trainee->id, $graded->trainee_id);
    }

    public function test_the_demo_dashboard_renders_its_headline_metrics_and_sandbox_badge(): void
    {
        app(DemoDataSeeder::class)->seedFor($this->demoTenant);

        $this->actingAsPortalUser($this->makePortalUser(PortalRole::Demo));

        $this->get('/demo')
            ->assertOk()
            // The sandbox badge is a render hook and the strapline a page
            // subheading, so both are in the first response.
            ->assertSee('Sandbox data')
            ->assertSee('Powering Every Lead');

        Livewire::test(PipelineStats::class)
            ->assertSee('Total Leads')
            ->assertSee('Qualified Leads')
            ->assertSee('Disbursal Amount')
            ->assertSee('Conversion Rate');
    }

    /**
     * Every chart builds a valid Chart.js payload against the seeded
     * sandbox — a chart that silently returns an empty dataset would
     * still render an (empty) canvas on the page.
     */
    public function test_every_demo_chart_produces_data(): void
    {
        app(DemoDataSeeder::class)->seedFor($this->demoTenant);

        $this->actingAsPortalUser($this->makePortalUser(PortalRole::Demo));

        /*
         * ChartWidget::mount() hashes its own payload into the public
         * dataChecksum property. Comparing that against the hash of the
         * empty payload our widgets fall back to (see
         * UsesDemoMetrics::emptyChart) proves each chart produced real
         * series — without reaching past the class's protected API.
         */
        $emptyChecksum = md5(json_encode(['datasets' => [], 'labels' => []]));

        foreach ([
            LeadTrendChart::class,
            LeadFunnelChart::class,
            ProductMixChart::class,
            DisbursalTrendChart::class,
            TeamPerformanceChart::class,
        ] as $chart) {
            $checksum = Livewire::test($chart)->get('dataChecksum');

            $this->assertNotNull($checksum, "{$chart} rendered no data at all.");
            $this->assertNotSame($emptyChecksum, $checksum, "{$chart} produced an empty chart.");
        }
    }

    public function test_every_demo_screen_renders(): void
    {
        app(DemoDataSeeder::class)->seedFor($this->demoTenant);

        $this->actingAsPortalUser($this->makePortalUser(PortalRole::Demo));

        foreach ([
            '/demo/leads',
            '/demo/customers',
            '/demo/applications',
            '/demo/follow-ups',
            '/demo/employees',
            '/demo/banks',
            '/demo/loan-products',
            '/demo/reports',
            '/demo/training',
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_the_demo_reports_page_shows_the_funnel_and_leaderboard(): void
    {
        app(DemoDataSeeder::class)->seedFor($this->demoTenant);

        $this->actingAsPortalUser($this->makePortalUser(PortalRole::Demo));

        $this->get('/demo/reports')
            ->assertOk()
            ->assertSee('Pipeline Funnel')
            ->assertSee('Team Leaderboard')
            ->assertSee('Lender Performance');
    }

    public function test_every_trainer_screen_renders(): void
    {
        $this->seedAcademy();

        $this->actingAsPortalUser($this->makePortalUser(PortalRole::Trainer));

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
            $this->get($url)->assertOk();
        }
    }

    private function seedAcademy(): TrainingCourse
    {
        return app(TrainingContentSeeder::class)->seedFor($this->production);
    }

    /**
     * @return array{0: User, 1: TrainingEnrollment}
     */
    private function seedTraineeWithProgress(): array
    {
        $course = $this->seedAcademy();
        $trainee = $this->makePortalUser(PortalRole::Trainee);

        $enrollment = TrainingEnrollment::factory()->assigned()->create([
            'tenant_id' => $this->production->id,
            'training_course_id' => $course->id,
            'trainee_id' => $trainee->id,
        ]);

        // A couple of completed lessons so the timeline has all three
        // states (done / current / upcoming) to render.
        $lessons = $course->modules()->with('lessons')->get()->flatMap->lessons->values();

        foreach ($lessons->take(2) as $lesson) {
            app(TrainingProgressService::class)->completeLesson($enrollment, $lesson);
        }

        return [$trainee, $enrollment->refresh()];
    }
}
