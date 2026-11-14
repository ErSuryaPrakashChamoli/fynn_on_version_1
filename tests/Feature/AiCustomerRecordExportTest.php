<?php

namespace Tests\Feature;

use AnourValar\EloquentSerialize\Facades\EloquentSerializeFacade;
use App\Filament\Actions\DataDownloadApproval;
use App\Filament\Exports\AiCustomerRecordExporter;
use App\Filament\Resources\AiCustomerRecords\Pages\ListAiCustomerRecords;
use App\Models\AiCustomerRecord;
use App\Models\AiDocumentSchema;
use App\Models\Customer;
use App\Models\CustomerAssignment;
use App\Models\DataDownloadAuthenticator;
use App\Models\Employee;
use App\Models\FollowUp;
use App\Models\User;
use App\Services\CustomerAssignmentService;
use App\Services\DataDownloadAuthenticatorService;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Models\Export;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * "Export Customer Data" on the Customer Data listing: one sheet with each
 * record's name, status, who holds it, since when, and the latest follow-up
 * status and remarks — Admin only, approved with the owner's code like every
 * other lead export.
 */
class AiCustomerRecordExportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Employee $adminEmployee;

    private Employee $caller;

    private AiDocumentSchema $schema;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin']);

        $this->adminEmployee = Employee::factory()->create(['emp_name' => 'Admin Person']);
        $this->admin = User::factory()->create(['employee_id' => $this->adminEmployee->id]);
        $this->admin->assignRole('Admin');
        $this->actingAs($this->admin);

        $this->caller = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
            'emp_name' => 'Asha Rao',
            'emp_id' => 'FYN-0142',
        ]);

        $this->schema = AiDocumentSchema::create([
            'name' => 'Axis Template Data',
            'is_active' => true,
            'fields' => [
                ['key' => 'customer_name', 'label' => 'Customer Name', 'type' => 'text'],
                ['key' => 'mobile', 'label' => 'Mobile', 'type' => 'mobile'],
            ],
        ]);

        Bus::fake();
    }

    public function test_the_admin_exports_customer_data_with_the_owner_code(): void
    {
        $authenticator = DataDownloadAuthenticator::factory()->create();
        $this->createRecord('Ravi Kumar');
        $this->createRecord('Meena Shah');

        Livewire::test(ListAiCustomerRecords::class)
            ->assertActionVisible(TestAction::make('export')->table())
            ->callAction(TestAction::make('export')->table(), [DataDownloadApproval::CODE_FIELD => $this->wrongCode($authenticator->secret)])
            ->assertHasActionErrors([DataDownloadApproval::CODE_FIELD]);

        $this->assertSame(0, Export::query()->count());

        Livewire::test(ListAiCustomerRecords::class)
            ->callAction(TestAction::make('export')->table(), [DataDownloadApproval::CODE_FIELD => (new Google2FA)->getCurrentOtp($authenticator->secret)])
            ->assertHasNoActionErrors();

        $export = Export::sole();
        $this->assertSame(AiCustomerRecordExporter::class, $export->exporter);
        $this->assertSame(2, $export->total_rows);
    }

    public function test_the_export_button_stays_disabled_until_the_owner_sets_up(): void
    {
        Livewire::test(ListAiCustomerRecords::class)
            ->assertActionVisible(TestAction::make('export')->table())
            ->assertActionDisabled(TestAction::make('export')->table());
    }

    public function test_each_row_shows_the_holder_the_assignment_date_and_the_latest_follow_up(): void
    {
        $followedUp = $this->createRecord('Ravi Kumar', '+91 98765 43210');
        $untouched = $this->createRecord('Meena Shah');
        $unassigned = $this->createRecord('Kiran Das');
        $converted = $this->createRecord('Farah Ali');

        app(CustomerAssignmentService::class)->assign(
            collect([$followedUp->id, $untouched->id, $converted->id]),
            $this->caller->id,
            CustomerAssignmentService::TARGET_AI_RECORD,
            $this->adminEmployee->id,
        );
        CustomerAssignment::query()->update(['created_at' => '2026-11-10 10:30:00']);

        $this->followUp(['ai_customer_record_id' => $followedUp->id], 'Call Back', 'Busy, call later', '2026-11-11 09:00:00');
        $this->followUp(['ai_customer_record_id' => $followedUp->id], 'Interested', 'Wants 5 lakh', '2026-11-12 16:15:00', '2026-11-15 11:00:00');

        // What CreateCustomer::afterCreate does on conversion: the record,
        // its follow-ups and its assignment move to the customer file.
        $customer = Customer::factory()->create();
        $converted->update(['customer_id' => $customer->id]);
        $this->followUp(['customer_id' => $customer->id], 'Eligible', 'Docs collected', '2026-11-13 12:00:00');
        CustomerAssignment::query()->where('ai_customer_record_id', $converted->id)->update([
            'customer_id' => $customer->id,
            'ai_customer_record_id' => null,
            'converted_at' => '2026-11-13 12:30:00',
        ]);

        $rows = $this->exportRows([$followedUp, $untouched, $unassigned, $converted]);

        $this->assertSame([
            'S. No.' => (string) $followedUp->id,
            'Configuration' => 'Axis Template Data',
            'Customer Name' => 'Ravi Kumar',
            'Mobile Number' => '9876543210',
            'Status' => 'Assigned',
            'Assigned To' => 'Asha Rao',
            'Assigned To Emp ID' => 'FYN-0142',
            'Assigned By' => 'Admin Person',
            'Assigned On' => '10 Nov 2026 10:30 AM',
            'Lead Status' => 'Interested',
            'Remarks' => 'Wants 5 lakh',
            'Last Follow-Up On' => '12 Nov 2026 04:15 PM',
            'Next Follow-Up' => '15 Nov 2026 11:00 AM',
            'Converted On' => '',
        ], array_diff_key($rows[$followedUp->id], ['Uploaded On' => true]));

        $this->assertSame('Pending', $rows[$untouched->id]['Lead Status']);
        $this->assertSame('', $rows[$untouched->id]['Remarks']);
        $this->assertSame('Asha Rao', $rows[$untouched->id]['Assigned To']);

        $this->assertSame('Approved', $rows[$unassigned->id]['Status']);
        $this->assertSame('', $rows[$unassigned->id]['Assigned To']);
        $this->assertSame('', $rows[$unassigned->id]['Assigned On']);
        $this->assertSame('', $rows[$unassigned->id]['Lead Status']);

        $this->assertSame('Converted', $rows[$converted->id]['Status']);
        $this->assertSame('Asha Rao', $rows[$converted->id]['Assigned To']);
        $this->assertSame('10 Nov 2026 10:30 AM', $rows[$converted->id]['Assigned On']);
        $this->assertSame('Eligible', $rows[$converted->id]['Lead Status']);
        $this->assertSame('Docs collected', $rows[$converted->id]['Remarks']);
        $this->assertSame('13 Nov 2026 12:30 PM', $rows[$converted->id]['Converted On']);
    }

    public function test_template_fields_are_offered_but_off_by_default(): void
    {
        $columns = collect(AiCustomerRecordExporter::getColumns())
            ->keyBy(fn (ExportColumn $column): string => $column->getName());

        $this->assertTrue($columns->has('data_field_mobile'));
        $this->assertFalse($columns['data_field_mobile']->isEnabledByDefault());
        $this->assertTrue($columns['assigned_to']->isEnabledByDefault());
    }

    private function createRecord(string $name, string $mobile = '9000000000'): AiCustomerRecord
    {
        return AiCustomerRecord::create([
            'schema_id' => $this->schema->id,
            'status' => 'approved',
            'data' => ['customer_name' => $name, 'mobile' => $mobile],
        ]);
    }

    /**
     * @param  array<string, int>  $subject
     */
    private function followUp(array $subject, string $status, string $remarks, string $at, ?string $nextAt = null): void
    {
        $followUp = FollowUp::create([
            ...$subject,
            'employee_id' => $this->caller->id,
            'follow_up_type' => 'Call',
            'status' => $status,
            'remarks' => $remarks,
            'next_follow_up_date' => $nextAt,
        ]);

        FollowUp::query()->whereKey($followUp->id)->update(['created_at' => $at]);
    }

    /**
     * The sheet rows keyed by S. No., built the way the queued export job
     * builds them: through the serialized query and every default column.
     *
     * @param  list<AiCustomerRecord>  $records
     * @return array<int, array<string, string>>
     */
    private function exportRows(array $records): array
    {
        $query = AiCustomerRecordExporter::modifyQuery(AiCustomerRecord::query());
        $query = EloquentSerializeFacade::unserialize(EloquentSerializeFacade::serialize($query));

        $columnMap = collect(AiCustomerRecordExporter::getColumns())
            ->filter(fn (ExportColumn $column): bool => $column->isEnabledByDefault())
            ->mapWithKeys(fn (ExportColumn $column): array => [$column->getName() => $column->getLabel()])
            ->all();

        $exporter = new AiCustomerRecordExporter(new Export, $columnMap, []);

        return $query->find(collect($records)->pluck('id'))
            ->mapWithKeys(fn (AiCustomerRecord $record): array => [
                $record->id => array_combine(array_values($columnMap), array_map(
                    fn (mixed $value): string => (string) $value,
                    $exporter($record),
                )),
            ])
            ->all();
    }

    private function wrongCode(string $secret): string
    {
        $service = app(DataDownloadAuthenticatorService::class);

        do {
            $code = sprintf('%06d', random_int(0, 999999));
        } while ($service->codeMatchesSecret($secret, $code));

        return $code;
    }
}
