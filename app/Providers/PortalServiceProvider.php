<?php

namespace App\Providers;

use App\Models\Demo\DemoApplication;
use App\Models\Demo\DemoBank;
use App\Models\Demo\DemoCustomer;
use App\Models\Demo\DemoEmployee;
use App\Models\Demo\DemoFollowUp;
use App\Models\Demo\DemoLead;
use App\Models\Demo\DemoLoanProduct;
use App\Models\Training\TrainingAttendance;
use App\Models\Training\TrainingBatch;
use App\Models\Training\TrainingCertificate;
use App\Models\Training\TrainingCourse;
use App\Models\Training\TrainingEnrollment;
use App\Models\Training\TrainingLesson;
use App\Models\Training\TrainingLessonDocument;
use App\Models\Training\TrainingModule;
use App\Models\Training\TrainingQuiz;
use App\Models\Training\TrainingQuizAttempt;
use App\Models\Training\TrainingRemark;
use App\Models\Training\TrainingSession;
use App\Policies\Demo\DemoRecordPolicy;
use App\Policies\Training\TrainingAttendancePolicy;
use App\Policies\Training\TrainingBatchPolicy;
use App\Policies\Training\TrainingCertificatePolicy;
use App\Policies\Training\TrainingContentPolicy;
use App\Policies\Training\TrainingCoursePolicy;
use App\Policies\Training\TrainingEnrollmentPolicy;
use App\Policies\Training\TrainingQuizAttemptPolicy;
use App\Policies\Training\TrainingRemarkPolicy;
use App\Support\Portal\PortalContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Wires up everything the Academy and Demo portals need, in one place
 * that the existing LMS never reads.
 *
 * Keeping this separate from AppServiceProvider is deliberate: the whole
 * portal feature can be reasoned about — or removed — without touching a
 * provider the live system depends on.
 */
class PortalServiceProvider extends ServiceProvider
{
    /**
     * Every policy the portals rely on. Registered explicitly rather than
     * by Laravel's naming convention, because a policy that silently
     * fails to resolve is a policy that silently authorises nothing —
     * and, for the resources that call authorize() only through
     * Filament, would fall back to the framework default.
     *
     * @var array<class-string, class-string>
     */
    protected array $policies = [
        TrainingCourse::class => TrainingCoursePolicy::class,
        TrainingModule::class => TrainingContentPolicy::class,
        TrainingLesson::class => TrainingContentPolicy::class,
        TrainingLessonDocument::class => TrainingContentPolicy::class,
        TrainingQuiz::class => TrainingContentPolicy::class,
        TrainingSession::class => TrainingContentPolicy::class,
        TrainingBatch::class => TrainingBatchPolicy::class,
        TrainingEnrollment::class => TrainingEnrollmentPolicy::class,
        TrainingQuizAttempt::class => TrainingQuizAttemptPolicy::class,
        TrainingAttendance::class => TrainingAttendancePolicy::class,
        TrainingCertificate::class => TrainingCertificatePolicy::class,
        TrainingRemark::class => TrainingRemarkPolicy::class,
        DemoLead::class => DemoRecordPolicy::class,
        DemoCustomer::class => DemoRecordPolicy::class,
        DemoEmployee::class => DemoRecordPolicy::class,
        DemoApplication::class => DemoRecordPolicy::class,
        DemoBank::class => DemoRecordPolicy::class,
        DemoLoanProduct::class => DemoRecordPolicy::class,
        DemoFollowUp::class => DemoRecordPolicy::class,
    ];

    public function register(): void
    {
        /*
         * Request-scoped so the middleware, the panel providers, every
         * resource's getEloquentQuery() and every policy resolve the
         * portal account once rather than per call. `scoped` (not
         * `singleton`) so a queue worker handling successive jobs never
         * carries one request's portal identity into the next.
         */
        $this->app->scoped(PortalContext::class);
    }

    public function boot(): void
    {
        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }
}
