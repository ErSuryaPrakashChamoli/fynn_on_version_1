<?php

namespace Tests\Feature;

use AnourValar\EloquentSerialize\Facades\EloquentSerializeFacade;
use App\Filament\Actions\DataDownloadApproval;
use App\Filament\Exports\AssignedLeadExporter;
use App\Filament\Resources\AssignedLeads\Pages\ListAssignedLeads;
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
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * "Export Assigned Leads" on the Assigned Leads listing: one sheet with each
 * lead's prospect, holder, hand-over details and latest follow-up — Admin
 * only, approved with the owner's code like "Export Customer Data".
 */
class AssignedLeadExportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Employee $adminEmployee;

    private Employee $asha;

    private Employee $ravi;

    private AiDocumentSchema $schema;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin']);

        $this->adminEmployee = Employee::factory()->create(['emp_name' => 'Admin Person', 'designation' => Employee::DESIGNATION_ADMIN]);
        $this->admin = User::factory()->create(['employee_id' => $this->adminEmployee->id]);
        $this->admin->assignRole('Admin');
        $this->actingAs($this->admin);

        $this->asha = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER, 'emp_name' => 'Asha Rao', 'emp_id' => 'FYN-0142']);
        $this->ravi = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER, 'emp_name' => 'Ravi Kumar', 'emp_id' => 'FYN-0200']);

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

    public function test_the_admin_exports_assigned_leads_with_the_owner_code(): void
    {
        $authenticator = DataDownloadAuthenticator::factory()->create();
        $this->assign($this->asha, 'Meena Shah');
        $this->assign($this->ravi, 'Kiran Das');

        Livewire::test(ListAssignedLeads::class)
            ->assertActionVisible(TestAction::make('export')->table())
            ->callAction(TestAction::make('export')->table(), [DataDownloadApproval::CODE_FIELD => $this->wrongCode($authenticator->secret)])
            ->assertHasActionErrors([DataDownloadApproval::CODE_FIELD]);

        $this->assertSame(0, Export::query()->count());

        Livewire::test(ListAssignedLeads::class)
            ->callAction(TestAction::make('export')->table(), [DataDownloadApproval::CODE_FIELD => (new Google2FA)->getCurrentOtp($authenticator->secret)])
            ->assertHasNoActionErrors();

        $export = Export::sole();
        $this->assertSame(AssignedLeadExporter::class, $export->exporter);
        $this->assertSame($this->admin->id, $export->user_id);
        $this->assertSame(2, $export->total_rows);
        $this->assertNotNull($authenticator->fresh()->last_used_at);

        $activity = Activity::query()->inLog(DataDownloadAuthenticatorService::LOG_NAME)->latest('id')->first();
        $this->assertSame("Downloaded assigned leads with the owner's approval", $activity->description);
        $this->assertSame($this->admin->id, $activity->causer_id);
    }

    public function test_a_used_code_cannot_approve_a_second_export(): void
    {
        $authenticator = DataDownloadAuthenticator::factory()->create();
        $this->assign($this->asha, 'Meena Shah');
        $code = (new Google2FA)->getCurrentOtp($authenticator->secret);

        Livewire::test(ListAssignedLeads::class)
            ->callAction(TestAction::make('export')->table(), [DataDownloadApproval::CODE_FIELD => $code])
            ->assertHasNoActionErrors();

        Livewire::test(ListAssignedLeads::class)
            ->callAction(TestAction::make('export')->table(), [DataDownloadApproval::CODE_FIELD => $code])
            ->assertHasActionErrors([DataDownloadApproval::CODE_FIELD]);

        $this->assertSame(1, Export::query()->count());
    }

    public function test_the_export_covers_only_what_the_listing_filters_show(): void
    {
        $authenticator = DataDownloadAuthenticator::factory()->create();
        $this->assign($this->asha, 'Meena Shah');
        $this->assign($this->asha, 'Farah Ali');
        $this->assign($this->ravi, 'Kiran Das');

        Livewire::test(ListAssignedLeads::class)
            ->filterTable('employee_id', [$this->asha->id])
            ->callAction(TestAction::make('export')->table(), [DataDownloadApproval::CODE_FIELD => (new Google2FA)->getCurrentOtp($authenticator->secret)])
            ->assertHasNoActionErrors();

        $this->assertSame(2, Export::sole()->total_rows);
    }

    public function test_the_export_button_stays_disabled_until_the_owner_sets_up(): void
    {
        Livewire::test(ListAssignedLeads::class)
            ->assertActionVisible(TestAction::make('export')->table())
            ->assertActionDisabled(TestAction::make('export')->table());
    }

    public function test_each_row_shows_the_holder_the_hand_over_and_the_latest_follow_up(): void
    {
        $followedUp = $this->assign($this->asha, 'Ravi Verma', '+91 98765 43210');
        $untouched = $this->assign($this->asha, 'Meena Shah');
        $converted = $this->assign($this->asha, 'Farah Ali');

        CustomerAssignment::query()->update(['created_at' => '2026-11-10 10:30:00']);

        $followedUp->recordOpen();
        $converted->recordOpen();
        $converted->recordOpen();
        $this->followUp(['ai_customer_record_id' => $followedUp->ai_customer_record_id], 'Call Back', 'Busy, call later', '2026-11-11 09:00:00');
        $this->followUp(['ai_customer_record_id' => $followedUp->ai_customer_record_id], 'Interested', 'Wants 5 lakh', '2026-11-12 16:15:00', '2026-11-15 11:00:00');

        app(CustomerAssignmentService::class)->reassign(collect([$followedUp->fresh()]), $this->ravi->id, $this->adminEmployee->id, 'Asha on leave');

        // What CreateCustomer::afterCreate does on conversion: the record,
        // its follow-ups and its assignment move to the customer file.
        $customer = Customer::factory()->create(['customer_name' => 'Farah Ali', 'journey_status' => 'sanctioned']);
        $converted->aiCustomerRecord->update(['customer_id' => $customer->id]);
        $this->followUp(['customer_id' => $customer->id], 'Eligible', 'Docs collected', '2026-11-13 12:00:00');
        $converted->update([
            'customer_id' => $customer->id,
            'ai_customer_record_id' => null,
            'converted_at' => '2026-11-13 12:30:00',
        ]);

        $rows = $this->exportRows([$followedUp, $untouched, $converted]);

        $this->assertSame([
            'ID' => (string) $followedUp->id,
            'Template' => 'Axis Template Data',
            'Prospect Name' => 'Ravi Verma',
            'Mobile Number' => '9876543210',
            'Source' => 'AI Record',
            'Case Owner' => 'Ravi Kumar',
            'Case Owner Emp ID' => 'FYN-0200',
            'Assigned By' => 'Admin Person',
            'Assigned On' => '10 Nov 2026 10:30 AM',
            // Reassignment resets the opens for the new holder.
            'Status' => 'Pending',
            'Opens' => '0',
            'Last Opened' => '',
            'Follow-Up Status' => 'Interested',
            'Remarks' => 'Wants 5 lakh',
            'Last Follow-Up On' => '12 Nov 2026 04:15 PM',
            'Next Follow-Up' => '15 Nov 2026 11:00 AM',
            'Journey' => '',
            'Reassigned' => '1',
            'Converted On' => '',
        ], array_diff_key($rows[$followedUp->id], ['Last Reassigned On' => true]));
        $this->assertNotSame('', $rows[$followedUp->id]['Last Reassigned On']);

        $this->assertSame('Pending', $rows[$untouched->id]['Follow-Up Status']);
        $this->assertSame('', $rows[$untouched->id]['Remarks']);
        $this->assertSame('', $rows[$untouched->id]['Last Opened']);
        $this->assertSame('Asha Rao', $rows[$untouched->id]['Case Owner']);
        $this->assertSame('0', $rows[$untouched->id]['Reassigned']);

        $this->assertSame('Farah Ali', $rows[$converted->id]['Prospect Name']);
        $this->assertSame('Customer', $rows[$converted->id]['Source']);
        $this->assertSame('Opened', $rows[$converted->id]['Status']);
        $this->assertSame('2', $rows[$converted->id]['Opens']);
        $this->assertNotSame('', $rows[$converted->id]['Last Opened']);
        $this->assertSame('Axis Template Data', $rows[$converted->id]['Template']);
        $this->assertSame('Eligible', $rows[$converted->id]['Follow-Up Status']);
        $this->assertSame('Docs collected', $rows[$converted->id]['Remarks']);
        $this->assertSame('Disbursed', $rows[$converted->id]['Journey']);
        $this->assertSame('13 Nov 2026 12:30 PM', $rows[$converted->id]['Converted On']);
    }

    private function assign(Employee $employee, string $name, string $mobile = '9000000000'): CustomerAssignment
    {
        $record = AiCustomerRecord::create([
            'schema_id' => $this->schema->id,
            'status' => 'approved',
            'data' => ['customer_name' => $name, 'mobile' => $mobile],
        ]);

        app(CustomerAssignmentService::class)->assign(
            collect([$record->id]),
            $employee->id,
            CustomerAssignmentService::TARGET_AI_RECORD,
            $this->adminEmployee->id,
        );

        return CustomerAssignment::query()->where('ai_customer_record_id', $record->id)->sole();
    }

    /**
     * @param  array<string, int>  $subject
     */
    private function followUp(array $subject, string $status, string $remarks, string $at, ?string $nextAt = null): void
    {
        $followUp = FollowUp::create([
            ...$subject,
            'employee_id' => $this->asha->id,
            'follow_up_type' => 'Call',
            'status' => $status,
            'remarks' => $remarks,
            'next_follow_up_date' => $nextAt,
        ]);

        FollowUp::query()->whereKey($followUp->id)->update(['created_at' => $at]);
    }

    /**
     * The sheet rows keyed by assignment id, built the way the queued export
     * job builds them: through the serialized query and every default column.
     *
     * @param  list<CustomerAssignment>  $assignments
     * @return array<int, array<string, string>>
     */
    private function exportRows(array $assignments): array
    {
        $query = AssignedLeadExporter::modifyQuery(CustomerAssignment::query());
        $query = EloquentSerializeFacade::unserialize(EloquentSerializeFacade::serialize($query));

        $columnMap = collect(AssignedLeadExporter::getColumns())
            ->filter(fn (ExportColumn $column): bool => $column->isEnabledByDefault())
            ->mapWithKeys(fn (ExportColumn $column): array => [$column->getName() => $column->getLabel()])
            ->all();

        $exporter = new AssignedLeadExporter(new Export, $columnMap, []);

        return $query->find(collect($assignments)->pluck('id'))
            ->mapWithKeys(fn (CustomerAssignment $assignment): array => [
                $assignment->id => array_combine(array_values($columnMap), array_map(
                    fn (mixed $value): string => (string) $value,
                    $exporter($assignment),
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
