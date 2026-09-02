<?php

namespace Tests\Feature;

use App\Filament\Resources\CustomerPanRequests\CustomerPanRequestResource;
use App\Models\Bank;
use App\Models\Customer;
use App\Models\CustomerPanRequest;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The "Duplicate PAN Request" listing used to be visible in full to any
 * authenticated user. This proves CustomerPanRequestResource::getEloquentQuery()
 * scopes it correctly: Admin sees everything, a caller sees only requests
 * they raised, and each of Team Leader/Manager/Cluster Manager sees only
 * the requests snapshotted under them at creation time.
 */
class CustomerPanRequestVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Employee $clusterManager;

    private Employee $manager;

    private Employee $teamLeader;

    private Employee $caller1;

    private Employee $caller2;

    private CustomerPanRequest $request1;

    private CustomerPanRequest $request2;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin']);

        $this->clusterManager = Employee::factory()->create(['designation' => Employee::DESIGNATION_CLUSTER]);
        $this->manager = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_MANAGER,
            'cluster_id' => $this->clusterManager->id,
        ]);
        $this->teamLeader = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_TEAM_LEADER,
            'manager_id' => $this->manager->id,
        ]);
        $this->caller1 = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'superviser_id' => $this->teamLeader->id,
            'manager_id' => $this->manager->id,
            'cluster_id' => $this->clusterManager->id,
        ]);
        $this->caller2 = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
        ]);

        $bank = Bank::create([
            'bank_name' => 'Test Bank',
            'loan_type' => 'personal',
            'is_active' => true,
        ]);

        $this->request1 = CustomerPanRequest::create([
            'customer_id' => Customer::factory()->create()->id,
            'pan_number' => 'ABCDE1234F',
            'requested_by' => $this->caller1->id,
            'requested_by_emp_id' => $this->caller1->emp_id,
            'requested_by_name' => $this->caller1->emp_name,
            'team_leader_id' => $this->teamLeader->id,
            'manager_id' => $this->manager->id,
            'cluster_manager_id' => $this->clusterManager->id,
            'requested_bank_id' => $bank->id,
            'requested_bank_name' => $bank->bank_name,
            'requested_loan_type' => 'personal',
            'status' => CustomerPanRequest::STATUS_PENDING,
        ]);

        $this->request2 = CustomerPanRequest::create([
            'customer_id' => Customer::factory()->create()->id,
            'pan_number' => 'ZYXWV9876G',
            'requested_by' => $this->caller2->id,
            'requested_by_emp_id' => $this->caller2->emp_id,
            'requested_by_name' => $this->caller2->emp_name,
            'requested_bank_id' => $bank->id,
            'requested_bank_name' => $bank->bank_name,
            'requested_loan_type' => 'personal',
            'status' => CustomerPanRequest::STATUS_PENDING,
        ]);
    }

    public function test_admin_sees_every_request(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        $ids = CustomerPanRequestResource::getEloquentQuery()->pluck('id');

        $this->assertEqualsCanonicalizing(
            [$this->request1->id, $this->request2->id],
            $ids->all()
        );
    }

    public function test_caller_sees_only_their_own_request(): void
    {
        $user = User::factory()->create(['employee_id' => $this->caller1->id]);
        $this->actingAs($user);

        $ids = CustomerPanRequestResource::getEloquentQuery()->pluck('id');

        $this->assertEqualsCanonicalizing([$this->request1->id], $ids->all());
    }

    public function test_team_leader_sees_only_requests_raised_under_them(): void
    {
        $user = User::factory()->create(['employee_id' => $this->teamLeader->id]);
        $this->actingAs($user);

        $ids = CustomerPanRequestResource::getEloquentQuery()->pluck('id');

        $this->assertEqualsCanonicalizing([$this->request1->id], $ids->all());
    }

    public function test_manager_sees_only_requests_raised_under_them(): void
    {
        $user = User::factory()->create(['employee_id' => $this->manager->id]);
        $this->actingAs($user);

        $ids = CustomerPanRequestResource::getEloquentQuery()->pluck('id');

        $this->assertEqualsCanonicalizing([$this->request1->id], $ids->all());
    }

    public function test_cluster_manager_sees_only_requests_raised_under_them(): void
    {
        $user = User::factory()->create(['employee_id' => $this->clusterManager->id]);
        $this->actingAs($user);

        $ids = CustomerPanRequestResource::getEloquentQuery()->pluck('id');

        $this->assertEqualsCanonicalizing([$this->request1->id], $ids->all());
    }
}
