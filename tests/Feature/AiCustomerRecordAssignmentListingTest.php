<?php

namespace Tests\Feature;

use App\Filament\Resources\AiCustomerRecords\Pages\ListAiCustomerRecords;
use App\Filament\Resources\AiCustomerRecords\Tables\AiCustomerRecordsTable;
use App\Models\AiCustomerRecord;
use App\Models\AiDocumentSchema;
use App\Models\CustomerAssignment;
use App\Models\CustomerAssignmentBatch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The Customer Data listing must show whom an AI record was handed to and let
 * the Admin split assigned records from the ones still waiting, without
 * touching the stored review status.
 */
class AiCustomerRecordAssignmentListingTest extends TestCase
{
    use RefreshDatabase;

    private Employee $caller;

    private AiCustomerRecord $assigned;

    private AiCustomerRecord $unassigned;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin']);

        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        $schema = AiDocumentSchema::create([
            'name' => 'Axis Template Data',
            'is_active' => true,
            'fields' => [
                ['key' => 'customer_name', 'label' => 'Customer Name', 'type' => 'text'],
                ['key' => 'mobile_number', 'label' => 'Mobile Number', 'type' => 'mobile'],
            ],
        ]);

        $this->caller = Employee::factory()->create([
            'emp_name' => 'Asha Rao',
            'emp_id' => 'EMP-0142',
            'designation' => Employee::DESIGNATION_CALLER,
        ]);

        $this->assigned = AiCustomerRecord::create([
            'schema_id' => $schema->id,
            'status' => 'approved',
            'data' => ['customer_name' => 'Megha S', 'mobile_number' => '7829077476'],
        ]);

        $this->unassigned = AiCustomerRecord::create([
            'schema_id' => $schema->id,
            'status' => 'approved',
            'data' => ['customer_name' => 'Bapi Das', 'mobile_number' => '9999212925'],
        ]);

        $batch = CustomerAssignmentBatch::create([
            'employee_id' => $this->caller->id,
            'customer_count' => 1,
        ]);

        CustomerAssignment::create([
            'batch_id' => $batch->id,
            'ai_customer_record_id' => $this->assigned->id,
            'employee_id' => $this->caller->id,
        ]);
    }

    public function test_listing_shows_the_assignee_and_an_assigned_status_badge(): void
    {
        Livewire::test(ListAiCustomerRecords::class)
            ->assertCanSeeTableRecords([$this->assigned, $this->unassigned])
            ->assertTableColumnStateSet('latestAssignment.employee.emp_name', 'Asha Rao', $this->assigned)
            ->assertTableColumnStateSet('latestAssignment.employee.emp_name', null, $this->unassigned)
            ->assertTableColumnStateSet('status', AiCustomerRecordsTable::STATUS_ASSIGNED, $this->assigned)
            ->assertTableColumnStateSet('status', 'approved', $this->unassigned)
            ->assertSee('EMP-0142');

        $this->assertSame('approved', $this->assigned->fresh()->status);
    }

    public function test_assignment_filter_splits_assigned_from_unassigned_records(): void
    {
        Livewire::test(ListAiCustomerRecords::class)
            ->filterTable('assigned', true)
            ->assertCanSeeTableRecords([$this->assigned])
            ->assertCanNotSeeTableRecords([$this->unassigned])
            ->filterTable('assigned', false)
            ->assertCanSeeTableRecords([$this->unassigned])
            ->assertCanNotSeeTableRecords([$this->assigned])
            ->filterTable('assigned', null)
            ->assertCanSeeTableRecords([$this->assigned, $this->unassigned]);
    }

    public function test_status_filter_treats_assigned_as_its_own_status(): void
    {
        Livewire::test(ListAiCustomerRecords::class)
            ->filterTable('status', AiCustomerRecordsTable::STATUS_ASSIGNED)
            ->assertCanSeeTableRecords([$this->assigned])
            ->assertCanNotSeeTableRecords([$this->unassigned])
            ->filterTable('status', 'approved')
            ->assertCanSeeTableRecords([$this->unassigned])
            ->assertCanNotSeeTableRecords([$this->assigned]);
    }

    public function test_assigned_to_filter_narrows_to_one_employee(): void
    {
        $other = Employee::factory()->create([
            'emp_name' => 'Nitin Thakur',
            'designation' => Employee::DESIGNATION_CALLER,
        ]);

        Livewire::test(ListAiCustomerRecords::class)
            ->filterTable('assigned_to', [$this->caller->id])
            ->assertCanSeeTableRecords([$this->assigned])
            ->assertCanNotSeeTableRecords([$this->unassigned])
            ->filterTable('assigned_to', [$other->id])
            ->assertCanNotSeeTableRecords([$this->assigned, $this->unassigned]);
    }

    public function test_assignee_and_status_columns_are_sortable_and_searchable(): void
    {
        Livewire::test(ListAiCustomerRecords::class)
            ->sortTable('latestAssignment.employee.emp_name')
            ->assertCanSeeTableRecords([$this->assigned, $this->unassigned])
            ->sortTable('status', 'desc')
            ->assertCanSeeTableRecords([$this->assigned, $this->unassigned], inOrder: true)
            ->searchTable('Asha')
            ->assertCanSeeTableRecords([$this->assigned])
            ->assertCanNotSeeTableRecords([$this->unassigned]);
    }
}
