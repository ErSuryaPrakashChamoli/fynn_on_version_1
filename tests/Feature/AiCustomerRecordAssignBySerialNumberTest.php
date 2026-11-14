<?php

namespace Tests\Feature;

use App\Filament\Resources\AiCustomerRecords\Pages\ListAiCustomerRecords;
use App\Models\AiCustomerRecord;
use App\Models\AiDocumentSchema;
use App\Models\Customer;
use App\Models\CustomerAssignment;
use App\Models\CustomerAssignmentBatch;
use App\Models\Employee;
use App\Models\User;
use App\Services\CustomerAssignmentService;
use App\Support\AiRecordSerialRanges;
use Closure;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The Admin can hand out slices of Customer Data by typing # (serial
 * number) ranges instead of ticking rows — several ranges to several users
 * in one go — sees which S. Nos. are still unassigned, and the bulk
 * "Assign to User" action keeps working through the same shared service.
 */
class AiCustomerRecordAssignBySerialNumberTest extends TestCase
{
    use RefreshDatabase;

    private Employee $caller;

    private AiDocumentSchema $schema;

    /** @var array<int, AiCustomerRecord> */
    private array $records = [];

    private Closure $undoRepeaterFake;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin']);

        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        $this->undoRepeaterFake = Repeater::fake();

        $this->schema = $schema = AiDocumentSchema::create([
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

    protected function tearDown(): void
    {
        ($this->undoRepeaterFake)();

        parent::tearDown();
    }

    public function test_range_action_assigns_every_unassigned_record_in_the_range(): void
    {
        [$first, $second, $third, $fourth, $fifth] = array_values($this->records);

        Livewire::test(ListAiCustomerRecords::class)
            ->callAction(TestAction::make('assignBySerialNumber')->table(), [
                'ranges' => [[
                    'from_id' => $second->id,
                    'to_id' => $fourth->id,
                    'employee_id' => $this->caller->id,
                ]],
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
                'ranges' => [[
                    'from_id' => $first->id,
                    'to_id' => $third->id,
                    'employee_id' => $this->caller->id,
                ]],
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
                'ranges' => [[
                    'from_id' => $lastId + 100,
                    'to_id' => $lastId + 200,
                    'employee_id' => $this->caller->id,
                ]],
            ])
            ->assertNotified('Nothing assigned');

        $this->assertDatabaseCount('customer_assignments', 0);
        $this->assertDatabaseCount('customer_assignment_batches', 0);
    }

    public function test_range_action_rejects_a_to_number_below_the_from_number(): void
    {
        Livewire::test(ListAiCustomerRecords::class)
            ->callAction(TestAction::make('assignBySerialNumber')->table(), [
                'ranges' => [[
                    'from_id' => 4,
                    'to_id' => 2,
                    'employee_id' => $this->caller->id,
                ]],
            ])
            ->assertHasFormErrors(['ranges.0.to_id']);

        $this->assertDatabaseCount('customer_assignments', 0);
    }

    public function test_one_submit_hands_several_ranges_to_several_users(): void
    {
        [$first, $second, $third, $fourth, $fifth] = array_values($this->records);
        $teamLeader = Employee::factory()->create(['designation' => Employee::DESIGNATION_TEAM_LEADER]);

        Livewire::test(ListAiCustomerRecords::class)
            ->callAction(TestAction::make('assignBySerialNumber')->table(), [
                'ranges' => [
                    ['from_id' => $first->id, 'to_id' => $second->id, 'employee_id' => $this->caller->id],
                    ['from_id' => $fourth->id, 'to_id' => $fifth->id, 'employee_id' => $teamLeader->id],
                ],
            ])
            ->assertHasNoFormErrors()
            ->assertNotified('Assigned');

        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id],
            CustomerAssignment::query()->where('employee_id', $this->caller->id)->pluck('ai_customer_record_id')->all(),
        );
        $this->assertEqualsCanonicalizing(
            [$fourth->id, $fifth->id],
            CustomerAssignment::query()->where('employee_id', $teamLeader->id)->pluck('ai_customer_record_id')->all(),
        );
        $this->assertDatabaseMissing('customer_assignments', ['ai_customer_record_id' => $third->id]);
        $this->assertSame(2, CustomerAssignmentBatch::query()->count());
    }

    public function test_overlapping_ranges_are_refused_so_no_record_goes_to_two_users(): void
    {
        [$first, , $third, $fourth] = array_values($this->records);
        $other = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER]);

        Livewire::test(ListAiCustomerRecords::class)
            ->callAction(TestAction::make('assignBySerialNumber')->table(), [
                'ranges' => [
                    ['from_id' => $first->id, 'to_id' => $third->id, 'employee_id' => $this->caller->id],
                    ['from_id' => $third->id, 'to_id' => $fourth->id, 'employee_id' => $other->id],
                ],
            ])
            ->assertHasFormErrors(['ranges.1.from_id']);

        $this->assertDatabaseCount('customer_assignments', 0);
    }

    public function test_a_lower_range_only_offers_what_the_ranges_above_left_free(): void
    {
        [$first, , $third, $fourth, $fifth] = array_values($this->records);

        $component = Livewire::test(ListAiCustomerRecords::class)
            ->mountAction(TestAction::make('assignBySerialNumber')->table())
            ->fillForm(['ranges' => [
                ['from_id' => $first->id, 'to_id' => $third->id, 'employee_id' => $this->caller->id],
                ['from_id' => null, 'to_id' => null],
            ]]);

        $component->assertSchemaComponentExists(
            'ranges.0.unassigned_range',
            checkComponentUsing: fn (Select $select): bool => array_keys($select->getOptions()) === ["{$first->id}-{$fifth->id}"],
        );
        $component->assertSchemaComponentExists(
            'ranges.1.unassigned_range',
            checkComponentUsing: fn (Select $select): bool => array_keys($select->getOptions()) === ["{$fourth->id}-{$fifth->id}"],
        );

        $component
            ->fillForm(['ranges.1.unassigned_range' => "{$fourth->id}-{$fifth->id}"])
            ->assertActionDataSet([
                'ranges.1.from_id' => $fourth->id,
                'ranges.1.to_id' => $fifth->id,
                'ranges.1.unassigned_range' => null,
            ]);
    }

    public function test_free_runs_are_cut_around_the_ranges_already_taken_by_rows_above(): void
    {
        [$first, $second, $third, $fourth, $fifth] = array_values($this->records);
        $third->delete();

        $free = AiRecordSerialRanges::without(AiRecordSerialRanges::unassigned(), [
            ['from' => $second->id, 'to' => $second->id],
        ]);

        $this->assertSame(
            [[$first->id, $first->id, 1], [$fourth->id, $fifth->id, 2]],
            $free->map(fn (array $range): array => [$range['from'], $range['to'], $range['count']])->all(),
        );

        $this->assertSame([], AiRecordSerialRanges::without(AiRecordSerialRanges::unassigned(), [
            ['from' => $first->id, 'to' => $fifth->id],
        ])->all());
    }

    public function test_the_modal_lists_the_unassigned_serial_numbers(): void
    {
        [, $second] = array_values($this->records);

        app(CustomerAssignmentService::class)->assign(collect([$second->id]), $this->caller->id, CustomerAssignmentService::TARGET_AI_RECORD, null);

        Livewire::test(ListAiCustomerRecords::class)
            ->mountAction(TestAction::make('assignBySerialNumber')->table())
            ->assertMountedActionModalSee([
                'Unassigned S. Nos.',
                (string) $this->records[1]->id,
                AiRecordSerialRanges::label(['from' => $this->records[3]->id, 'to' => $this->records[5]->id]),
                'Axis Template Data',
            ]);
    }

    public function test_picking_an_unassigned_range_fills_from_and_to(): void
    {
        [$first, , $third] = array_values($this->records);

        Livewire::test(ListAiCustomerRecords::class)
            ->mountAction(TestAction::make('assignBySerialNumber')->table())
            ->fillForm(['ranges' => [['unassigned_range' => "{$first->id}-{$third->id}"]]])
            ->assertActionDataSet([
                'ranges.0.from_id' => $first->id,
                'ranges.0.to_id' => $third->id,
            ]);
    }

    public function test_unassigned_runs_break_at_taken_records_and_other_templates_but_not_at_deleted_ids(): void
    {
        [$first, $second, $third, $fourth, $fifth] = array_values($this->records);

        $otherSchema = AiDocumentSchema::create([
            'name' => 'HDFC Template Data',
            'is_active' => true,
            'fields' => [['key' => 'customer_name', 'label' => 'Customer Name', 'type' => 'text']],
        ]);
        $otherTemplateRecord = AiCustomerRecord::create(['schema_id' => $otherSchema->id, 'status' => 'approved', 'data' => []]);
        $afterOtherTemplate = AiCustomerRecord::create(['schema_id' => $this->schema->id, 'status' => 'approved', 'data' => []]);

        app(CustomerAssignmentService::class)->assign(collect([$third->id]), $this->caller->id, CustomerAssignmentService::TARGET_AI_RECORD, null);
        $second->delete();

        $this->assertSame(
            [
                ['from' => $first->id, 'to' => $first->id, 'count' => 1, 'configuration' => 'Axis Template Data'],
                ['from' => $fourth->id, 'to' => $fifth->id, 'count' => 2, 'configuration' => 'Axis Template Data'],
                ['from' => $otherTemplateRecord->id, 'to' => $otherTemplateRecord->id, 'count' => 1, 'configuration' => 'HDFC Template Data'],
                ['from' => $afterOtherTemplate->id, 'to' => $afterOtherTemplate->id, 'count' => 1, 'configuration' => 'Axis Template Data'],
            ],
            AiRecordSerialRanges::unassigned()
                ->map(fn (array $range): array => array_diff_key($range, ['uploaded_on' => true]))
                ->all(),
        );
    }

    public function test_a_converted_record_is_neither_listed_as_unassigned_nor_assigned_again(): void
    {
        [$first, $second] = array_values($this->records);

        app(CustomerAssignmentService::class)->assign(collect([$first->id]), $this->caller->id, CustomerAssignmentService::TARGET_AI_RECORD, null);

        // What CreateCustomer::afterCreate does when the lead is converted.
        $customer = Customer::factory()->create();
        $first->update(['customer_id' => $customer->id]);
        CustomerAssignment::query()->where('ai_customer_record_id', $first->id)->update([
            'customer_id' => $customer->id,
            'ai_customer_record_id' => null,
            'converted_at' => now(),
        ]);

        $this->assertNotContains($first->id, AiCustomerRecord::query()->unassigned()->pluck('id')->all());
        $this->assertSame($second->id, AiRecordSerialRanges::unassigned()->first()['from']);

        $other = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER]);

        Livewire::test(ListAiCustomerRecords::class)
            ->callAction(TestAction::make('assignBySerialNumber')->table(), [
                'ranges' => [['from_id' => $first->id, 'to_id' => $second->id, 'employee_id' => $other->id]],
            ])
            ->assertNotified('Assigned');

        $this->assertSame(1, CustomerAssignment::query()->where('employee_id', $other->id)->count());
        $this->assertSame($second->id, CustomerAssignment::query()->where('employee_id', $other->id)->sole()->ai_customer_record_id);
        $this->assertSame($this->caller->id, CustomerAssignment::query()->where('customer_id', $customer->id)->sole()->employee_id);
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
