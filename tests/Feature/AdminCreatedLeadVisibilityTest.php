<?php

namespace Tests\Feature;

use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Http\Middleware\EnsureMonthlyTargetIsSet;
use App\Models\CustomerAssignment;
use App\Models\Employee;
use App\Models\Lead;
use App\Models\User;
use App\Support\BureauCheckCandidates;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A lead's owner is its creator's employee record. An Admin login without
 * an employee record therefore creates leads with no owner; the Admin —
 * company-wide — must still see them, in My Leads and in the CIBIL lead
 * picker. Everyone else keeps their own reporting-tree scope.
 */
class AdminCreatedLeadVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $caller;

    private Employee $callerEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'Caller', 'guard_name' => 'web']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutMiddleware(EnsureMonthlyTargetIsSet::class);

        // As on the live data: the Admin login has no employee record.
        $this->admin = User::factory()->create(['employee_id' => null]);
        $this->admin->assignRole('Admin');

        $this->callerEmployee = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER, 'exit_status' => 'no']);
        $this->caller = User::factory()->create(['employee_id' => $this->callerEmployee->id]);
        $this->caller->assignRole('Caller');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function lead(array $attributes = []): Lead
    {
        return Lead::create([
            'customer_name' => 'Admin Created Lead',
            'mobile_no' => '9811122233',
            'pan_number' => 'ADMIN1234L',
            'follow_up_type' => 'Call',
            'status' => 'Interested',
            'remarks' => 'Created from the Admin login',
            ...$attributes,
        ]);
    }

    public function test_a_lead_created_by_an_admin_without_an_employee_record_is_listed_for_the_admin(): void
    {
        // What the lead form's hidden owner field saves for this Admin.
        $this->actingAs($this->admin);
        $this->assertNull(auth()->user()->employee?->id);

        $ownerless = $this->lead(['employee_id' => null]);
        $callers = $this->lead(['customer_name' => 'Caller Lead', 'employee_id' => $this->callerEmployee->id]);
        $converted = $this->lead(['customer_name' => 'Converted Lead', 'employee_id' => null, 'is_converted' => true]);

        Livewire::test(ListLeads::class)
            ->assertCanSeeTableRecords([$ownerless, $callers])
            ->assertCanNotSeeTableRecords([$converted])
            ->searchTable('Admin Created')
            ->assertCanSeeTableRecords([$ownerless]);
    }

    public function test_everyone_else_keeps_their_own_scope(): void
    {
        $ownerless = $this->lead(['employee_id' => null]);
        $callers = $this->lead(['customer_name' => 'Caller Lead', 'employee_id' => $this->callerEmployee->id]);

        $this->actingAs($this->caller);

        $this->assertSame([$callers->id], LeadResource::getEloquentQuery()->pluck('id')->all());
        $this->assertNotContains($ownerless->id, LeadResource::getEloquentQuery()->pluck('id')->all());
    }

    public function test_the_admin_can_pick_an_ownerless_lead_for_a_cibil_check(): void
    {
        $ownerless = $this->lead(['employee_id' => null, 'status' => CustomerAssignment::BUREAU_CHECK_STATUS]);

        $this->actingAs($this->admin);

        $this->assertArrayHasKey('lead:'.$ownerless->id, BureauCheckCandidates::leadOptions()['My Leads'] ?? []);
        $this->assertTrue(BureauCheckCandidates::resolveLead('lead:'.$ownerless->id)?->is($ownerless));
    }
}
