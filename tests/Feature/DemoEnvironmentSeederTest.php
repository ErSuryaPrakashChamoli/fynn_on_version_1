<?php

namespace Tests\Feature;

use App\Enums\EligibilityRequestStatus;
use App\Filament\Resources\CustomerEligibilityRequests\Schemas\CustomerEligibilityRequestForm;
use App\Models\Customer;
use App\Models\CustomerEligibilityLog;
use App\Models\CustomerEligibilityRequest;
use App\Models\User;
use App\Services\CustomerEligibilityService;
use App\Services\DailyCommitmentGate;
use App\Services\MonthlyTargetGate;
use Database\Seeders\DemoEnvironment\DemoEnvironmentSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The client demo database must let every persona straight in: a login
 * for each, no target or commitment gate standing in the way, and the
 * modules they open already holding data for the current month.
 */
class DemoEnvironmentSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Mid-afternoon on a working day, before the evening declaration.
        Carbon::setTestNow(Carbon::parse('2026-09-21 14:30:00'));
        Filament::setCurrentPanel('admin');

        $this->seed(DemoEnvironmentSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_every_persona_has_an_active_login_that_reaches_the_admin_panel(): void
    {
        foreach (config('demo.personas') as $slug => $persona) {
            $user = User::query()->where('email', $persona['email'])->first();

            $this->assertNotNull($user, "Persona [{$slug}] was not seeded.");
            $this->assertTrue($user->hasRole($persona['label']), "Persona [{$slug}] is missing its [{$persona['label']}] role.");
            $this->assertTrue($user->canAccessPanel(Filament::getPanel('admin')), "Persona [{$slug}] cannot open /admin.");
        }
    }

    public function test_no_persona_is_blocked_by_the_monthly_target_or_daily_commitment_gates(): void
    {
        foreach (config('demo.personas') as $slug => $persona) {
            $user = User::query()->where('email', $persona['email'])->first();

            $this->assertFalse(app(MonthlyTargetGate::class)->isBlocked($user), "Persona [{$slug}] is blocked by the monthly target gate.");
            $this->assertFalse(app(DailyCommitmentGate::class)->isBlocked($user), "Persona [{$slug}] is blocked by the daily commitment prompt.");
        }
    }

    public function test_the_persona_caller_has_live_work_this_month(): void
    {
        $caller = User::query()->where('email', config('demo.personas.caller.email'))->first();
        $customers = Customer::query()->where('assign_to', $caller->employee_id);

        $this->assertTrue((clone $customers)->whereBetween('disbursal_date', [now()->startOfMonth(), now()])->exists());
        $this->assertTrue((clone $customers)->whereIn('journey_status', ['sfl', 'underwriting', 'approved'])->exists());
        $this->assertDatabaseHas('leads', ['employee_id' => $caller->employee_id, 'is_converted' => false]);
        $this->assertDatabaseHas('daily_commitments', ['employee_id' => $caller->employee_id, 'date' => now()->toDateString()]);
    }

    public function test_the_eligibility_module_has_something_to_show_for_every_persona(): void
    {
        $caller = User::query()->where('email', config('demo.personas.caller.email'))->first();
        $admin = User::query()->where('email', config('demo.personas.admin.email'))->first();
        $service = app(CustomerEligibilityService::class);

        // Every file carries at least its creation line.
        $this->assertSame(0, Customer::query()->whereDoesntHave('eligibilityLogs')->count());

        foreach (EligibilityRequestStatus::cases() as $status) {
            $this->assertTrue(CustomerEligibilityRequest::query()->where('status', $status->value)->exists(), "No {$status->value} eligibility request was seeded.");
        }

        // Approved requests left their files Eligible, pending ones Not Eligible.
        $this->assertSame(0, CustomerEligibilityRequest::query()->where('status', 'approved')->whereHas('customer', fn ($q) => $q->where('eligibility_status', '!=', 'eligible'))->count());
        $this->assertSame(0, CustomerEligibilityRequest::query()->where('status', 'pending')->whereHas('customer', fn ($q) => $q->where('eligibility_status', '!=', 'not_eligible'))->count());
        $this->assertTrue(CustomerEligibilityLog::query()->where('created_at', '>', now())->doesntExist());

        // The caller can demo all three paths live.
        $callerFiles = Customer::query()->where('assign_to', $caller->employee_id)->get();
        $this->assertTrue($callerFiles->contains(fn (Customer $c): bool => $service->pendingRequest($c) !== null), 'Caller has no request waiting for the Admin.');
        $this->assertTrue($callerFiles->contains(fn (Customer $c): bool => $service->canRaiseRequest($caller, $c)), 'Caller has no Not Eligible file left to request.');
        $this->assertTrue($callerFiles->contains(fn (Customer $c): bool => $service->canChangeStatus($caller, $c)), 'Caller has no Consent Pending file to change.');

        $this->actingAs($caller);
        $this->assertTrue(CustomerEligibilityRequestForm::requestableCustomers()->exists());

        $this->assertTrue(CustomerEligibilityService::isReviewer($admin));
    }

    public function test_generated_people_never_use_a_real_company_domain(): void
    {
        $this->assertSame(0, User::query()->where('email', 'not like', '%@fynnon-demo.test')->count());
    }
}
