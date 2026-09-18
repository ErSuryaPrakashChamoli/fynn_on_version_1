<?php

namespace Tests\Feature;

use App\Filament\Resources\AiCustomerRecords\Pages\ListAiCustomerRecords;
use App\Models\AiCustomerRecord;
use App\Models\AiDocumentSchema;
use App\Models\CustomerAssignment;
use App\Models\CustomerAssignmentBatch;
use App\Models\Employee;
use App\Models\User;
use App\Services\CustomerAssignmentService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The Admin can hand out a slice of Customer Data by typing a # (serial
 * number) range instead of ticking rows, and the bulk "Assign to User"
 * action keeps working through the same shared service.
 */
class AiCustomerRecordAssignBySerialNumberTest extends TestCase
{
    use RefreshDatabase;

    private Employee $caller;

    /** @var array<int, AiCustomerRecord> */
    private array $records = [];

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
            'fields' => [['key' => 'customer_name', 'label' => 'Customer Name', 'type' => 'text']],
        ]);

        $this->caller = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER]);

        foreach (range(1, 5) as $index) {
            $this->records[$index] = AiCustomerRecord::create([
                'schema_id' => $schema->id,
                'status' => 'approved',
                'data' => ['customer_name' => "Customer {$index}"],
            ]);
        }
    }

    public function test_range_action_assigns_every_unassigned_record_in_the_range(): void
    {
        [$first, $second, $third, $fourth, $fifth] = array_values($this->records);

        Livewire::test(ListAiCustomerRecords::class)
            ->callAction(TestAction::make('assignBySerialNumber')->table(), [
                'from_id' => $second->id,
                'to_id' => $fourth->id,
                'employee_id' => $this->caller->id,
            ])
            ->assertHasNoFormErrors()
            ->assertNotified('Assigned');

        $assignedIds = CustomerAssignment::query()->pluck('ai_customer_record_id')->all();

        $this->assertEqualsCanonicalizing([$second->id, $third->id, $fourth->id], $assignedIds);
        $this->assertDatabaseMissing('customer_assignments', ['ai_customer_record_id' => $first->id]);
        $this->assertDatabaseMissing('customer_assignments', ['ai_customer_record_id' => $fifth->id]);

        $batch = CustomerAssignmentBatch::sole();
        $this->assertSame($this->caller->id, $batch->employee_id);
        $this->assertSame(3, $batch->customer_count);
    }

    public function test_range_action_skips_records_that_are_already_assigned(): void
    {
        [$first, $second, $third] = array_values($this->records);

        $other = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER]);

        app(CustomerAssignmentService::class)->assign(
            collect([$second->id]),
            $other->id,
            CustomerAssignmentService::TARGET_AI_RECORD,
            null,
        );

        Livewire::test(ListAiCustomerRecords::class)
            ->callAction(TestAction::make('assignBySerialNumber')->table(), [
                'from_id' => $first->id,
                'to_id' => $third->id,
                'employee_id' => $this->caller->id,
            ])
            ->assertHasNoFormErrors()
            ->assertNotified('Assigned');

        $this->assertSame($other->id, CustomerAssignment::where('ai_customer_record_id', $second->id)->sole()->employee_id);
        $this->assertSame($this->caller->id, CustomerAssignment::where('ai_customer_record_id', $first->id)->sole()->employee_id);
        $this->assertSame($this->caller->id, CustomerAssignment::where('ai_customer_record_id', $third->id)->sole()->employee_id);
        $this->assertSame(2, CustomerAssignmentBatch::latest('id')->first()->customer_count);
    }

    public function test_range_action_warns_when_nothing_is_left_to_assign(): void
    {
        $lastId = end($this->records)->id;

        Livewire::test(ListAiCustomerRecords::class)
            ->callAction(TestAction::make('assignBySerialNumber')->table(), [
                'from_id' => $lastId + 100,
                'to_id' => $lastId + 200,
                'employee_id' => $this->caller->id,
            ])
            ->assertNotified('Nothing assigned');

        $this->assertDatabaseCount('customer_assignments', 0);
        $this->assertDatabaseCount('customer_assignment_batches', 0);
    }

    public function test_range_action_rejects_a_to_number_below_the_from_number(): void
    {
        Livewire::test(ListAiCustomerRecords::class)
            ->callAction(TestAction::make('assignBySerialNumber')->table(), [
                'from_id' => 4,
                'to_id' => 2,
                'employee_id' => $this->caller->id,
            ])
            ->assertHasFormErrors(['to_id']);

        $this->assertDatabaseCount('customer_assignments', 0);
    }

    public function test_bulk_assign_action_still_assigns_selected_records(): void
    {
        [$first, $second] = array_values($this->records);

        Livewire::test(ListAiCustomerRecords::class)
            ->selectTableRecords([$first, $second])
            ->callAction(TestAction::make('assignToUser')->table()->bulk(), [
                'employee_id' => $this->caller->id,
            ])
            ->assertNotified('Assigned');

        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id],
            CustomerAssignment::query()->pluck('ai_customer_record_id')->all(),
        );
        $this->assertSame(2, CustomerAssignmentBatch::sole()->customer_count);
    }

    public function test_service_reports_assigned_and_skipped_counts(): void
    {
        [$first, $second] = array_values($this->records);
        $service = app(CustomerAssignmentService::class);

        $firstRun = $service->assign(collect([$first->id]), $this->caller->id, CustomerAssignmentService::TARGET_AI_RECORD, null);
        $secondRun = $service->assign(collect([$first->id, $second->id, $second->id]), $this->caller->id, CustomerAssignmentService::TARGET_AI_RECORD, null);
        $emptyRun = $service->assign(collect([]), $this->caller->id, CustomerAssignmentService::TARGET_AI_RECORD, null);

        $this->assertSame(['assigned' => 1, 'skipped' => 0], ['assigned' => $firstRun['assigned'], 'skipped' => $firstRun['skipped']]);
        $this->assertSame(['assigned' => 1, 'skipped' => 1], ['assigned' => $secondRun['assigned'], 'skipped' => $secondRun['skipped']]);
        $this->assertSame(['assigned' => 0, 'skipped' => 0, 'batch' => null], $emptyRun);
        $this->assertDatabaseCount('customer_assignments', 2);
    }
}
