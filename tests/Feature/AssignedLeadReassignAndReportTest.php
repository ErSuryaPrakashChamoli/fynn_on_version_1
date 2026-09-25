<?php

namespace Tests\Feature;

use App\Filament\Resources\AssignedLeads\Pages\EditAssignedLead;
use App\Filament\Resources\AssignedLeads\Pages\ListAssignedLeads;
use App\Filament\Resources\LeadAssignmentReports\Pages\ListLeadAssignmentReports;
use App\Filament\Resources\LeadAssignmentReports\Tables\LeadAssignmentReportsTable;
use App\Filament\Resources\LeadAssignmentReports\Widgets\LeadAssignmentSummary;
use App\Models\AiCustomerRecord;
use App\Models\AiDocumentSchema;
use App\Models\CustomerAssignment;
use App\Models\CustomerAssignmentTransfer;
use App\Models\Employee;
use App\Models\FollowUp;
use App\Models\User;
use App\Services\CustomerAssignmentService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Assigned Leads: template column, the new filters, repeatable reassignment,
 * and the Lead Assignment report built on the same lead scope.
 */
class AssignedLeadReassignAndReportTest extends TestCase
{
    use RefreshDatabase;

    private Employee $admin;

    private Employee $asha;

    private Employee $ravi;

    private Employee $meera;

    private AiDocumentSchema $axisTemplate;

    private AiDocumentSchema $hdfcTemplate;

    private CustomerAssignment $interestedLead;

    private CustomerAssignment $untouchedLead;

    private CustomerAssignment $hdfcLead;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin']);

        $this->admin = Employee::factory()->create(['emp_name' => 'Admin Person', 'emp_id' => 'ADM-0001', 'designation' => Employee::DESIGNATION_ADMIN]);
        $this->asha = Employee::factory()->create(['emp_name' => 'Asha Rao', 'emp_id' => 'EMP-0142', 'designation' => Employee::DESIGNATION_CALLER]);
        $this->ravi = Employee::factory()->create(['emp_name' => 'Ravi Kumar', 'emp_id' => 'EMP-0200', 'designation' => Employee::DESIGNATION_CALLER]);
        $this->meera = Employee::factory()->create(['emp_name' => 'Meera Nair', 'emp_id' => 'EMP-0300', 'designation' => Employee::DESIGNATION_CALLER]);

        $user = User::factory()->create(['employee_id' => $this->admin->id]);
        $user->assignRole('Admin');
        $this->actingAs($user);

        $this->axisTemplate = AiDocumentSchema::create(['name' => 'Axis Template Data', 'is_active' => true, 'fields' => []]);
        $this->hdfcTemplate = AiDocumentSchema::create(['name' => 'HDFC Template Data', 'is_active' => true, 'fields' => []]);

        $service = app(CustomerAssignmentService::class);

        $axisRecords = collect([
            $this->aiRecord($this->axisTemplate, 'Deepika Dogra'),
            $this->aiRecord($this->axisTemplate, 'Nimisha P'),
        ]);
        $service->assign($axisRecords->pluck('id'), $this->asha->id, CustomerAssignmentService::TARGET_AI_RECORD, $this->admin->id);

        $hdfcRecord = $this->aiRecord($this->hdfcTemplate, 'Uday Kumar Giri');
        $service->assign(collect([$hdfcRecord->id]), $this->ravi->id, CustomerAssignmentService::TARGET_AI_RECORD, null);

        $this->interestedLead = CustomerAssignment::where('ai_customer_record_id', $axisRecords[0]->id)->firstOrFail();
        $this->untouchedLead = CustomerAssignment::where('ai_customer_record_id', $axisRecords[1]->id)->firstOrFail();
        $this->hdfcLead = CustomerAssignment::where('ai_customer_record_id', $hdfcRecord->id)->firstOrFail();

        $this->interestedLead->recordOpen();

        FollowUp::create([
            'ai_customer_record_id' => $axisRecords[0]->id,
            'employee_id' => $this->asha->id,
            'follow_up_type' => 'Call',
            'status' => 'Interested',
            'remarks' => 'Wants a callback',
            'next_follow_up_date' => now()->subDay(),
        ]);
    }

    private function aiRecord(AiDocumentSchema $template, string $name): AiCustomerRecord
    {
        return AiCustomerRecord::create([
            'schema_id' => $template->id,
            'status' => 'approved',
            'data' => ['customer_name' => $name],
        ]);
    }

    public function test_edit_page_prefills_prospect_details_and_latest_follow_up(): void
    {
        $this->interestedLead->aiCustomerRecord->update(['data' => [
            'customer_name' => 'Deepika Dogra',
            'mobile_number' => '9876543210',
            'pan_number' => 'abcde1234f',
            'email' => 'deepika@example.com',
        ]]);

        $nextFollowUp = $this->interestedLead->latestFollowUp()->next_follow_up_date;

        Livewire::test(EditAssignedLead::class, ['record' => $this->interestedLead->getRouteKey()])
            ->assertFormSet([
                'customer_name' => 'Deepika Dogra',
                'mobile_no' => '9876543210',
                'pan_number' => 'ABCDE1234F',
                'email' => 'deepika@example.com',
                'status' => 'Interested',
                'next_follow_up_date' => $nextFollowUp->format('Y-m-d H:i'),
                'remarks' => null,
            ]);
    }

    public function test_edit_page_defaults_status_to_pending_for_an_untouched_lead(): void
    {
        Livewire::test(EditAssignedLead::class, ['record' => $this->untouchedLead->getRouteKey()])
            ->assertFormSet([
                'customer_name' => 'Nimisha P',
                'status' => 'Pending',
            ]);
    }

    public function test_assignment_snapshots_the_template_and_listing_shows_it_instead_of_source(): void
    {
        $this->assertSame($this->axisTemplate->id, $this->interestedLead->ai_document_schema_id);

        Livewire::test(ListAssignedLeads::class)
            ->assertTableColumnExists('template.name')
            ->assertTableColumnDoesNotExist('source_label')
            ->assertTableColumnStateSet('template.name', 'Axis Template Data', $this->interestedLead)
            ->assertTableColumnStateSet('template.name', 'HDFC Template Data', $this->hdfcLead);
    }

    public function test_template_survives_conversion_to_a_customer(): void
    {
        $this->interestedLead->update(['ai_customer_record_id' => null, 'converted_at' => now()]);

        $this->assertSame('Axis Template Data', $this->interestedLead->fresh()->template_name);
    }

    public function test_listing_filters_by_template_case_owner_emp_id_and_assigned_by(): void
    {
        Livewire::test(ListAssignedLeads::class)
            ->filterTable('template', [$this->hdfcTemplate->id])
            ->assertCanSeeTableRecords([$this->hdfcLead])
            ->assertCanNotSeeTableRecords([$this->interestedLead, $this->untouchedLead])
            ->resetTableFilters()
            ->filterTable('employee_id', [$this->asha->id])
            ->assertCanSeeTableRecords([$this->interestedLead, $this->untouchedLead])
            ->assertCanNotSeeTableRecords([$this->hdfcLead])
            ->resetTableFilters()
            ->filterTable('emp_id', [$this->ravi->id])
            ->assertCanSeeTableRecords([$this->hdfcLead])
            ->assertCanNotSeeTableRecords([$this->interestedLead])
            ->resetTableFilters()
            ->filterTable('assigned_by', [$this->admin->id])
            ->assertCanSeeTableRecords([$this->interestedLead, $this->untouchedLead])
            ->assertCanNotSeeTableRecords([$this->hdfcLead]);
    }

    public function test_listing_filters_by_follow_up_status_untouched_and_overdue(): void
    {
        Livewire::test(ListAssignedLeads::class)
            ->filterTable('follow_up_status', ['Interested'])
            ->assertCanSeeTableRecords([$this->interestedLead])
            ->assertCanNotSeeTableRecords([$this->untouchedLead, $this->hdfcLead])
            ->resetTableFilters()
            ->filterTable('follow_up_status', ['Pending'])
            ->assertCanSeeTableRecords([$this->untouchedLead, $this->hdfcLead])
            ->assertCanNotSeeTableRecords([$this->interestedLead])
            ->resetTableFilters()
            ->filterTable('open_status', 'untouched')
            ->assertCanSeeTableRecords([$this->untouchedLead, $this->hdfcLead])
            ->assertCanNotSeeTableRecords([$this->interestedLead])
            ->resetTableFilters()
            ->filterTable('overdue', true)
            ->assertCanSeeTableRecords([$this->interestedLead])
            ->assertCanNotSeeTableRecords([$this->untouchedLead, $this->hdfcLead]);
    }

    public function test_assigned_on_filter_replaces_the_topbar_month(): void
    {
        $oldLead = $this->hdfcLead;
        $oldLead->forceFill(['created_at' => now()->subMonths(3)])->save();

        Livewire::test(ListAssignedLeads::class)
            ->assertCanNotSeeTableRecords([$oldLead])
            ->filterTable('assigned_on', [
                'assigned_from' => now()->subMonths(4)->toDateString(),
                'assigned_until' => now()->subMonths(2)->toDateString(),
            ])
            ->assertCanSeeTableRecords([$oldLead])
            ->assertCanNotSeeTableRecords([$this->interestedLead]);
    }

    public function test_a_lead_can_be_reassigned_more_than_once_and_every_move_is_logged(): void
    {
        Livewire::test(ListAssignedLeads::class)
            ->callAction(TestAction::make('reassign')->table($this->interestedLead), [
                'employee_id' => $this->ravi->id,
                'reason' => 'Asha on leave',
            ])
            ->assertNotified();

        $lead = $this->interestedLead->fresh();
        $this->assertSame($this->ravi->id, $lead->employee_id);
        $this->assertSame(1, $lead->reassign_count);
        $this->assertSame(0, $lead->opens_count);

        Livewire::test(ListAssignedLeads::class)
            ->callAction(TestAction::make('reassign')->table($lead), ['employee_id' => $this->meera->id])
            ->assertNotified();

        $lead->refresh();
        $this->assertSame($this->meera->id, $lead->employee_id);
        $this->assertSame(2, $lead->reassign_count);

        $transfers = CustomerAssignmentTransfer::where('customer_assignment_id', $lead->id)->orderBy('id')->get();
        $this->assertCount(2, $transfers);
        $this->assertSame([$this->asha->id, $this->ravi->id], $transfers->pluck('from_employee_id')->all());
        $this->assertSame([$this->ravi->id, $this->meera->id], $transfers->pluck('to_employee_id')->all());
        $this->assertSame('Asha on leave', $transfers->first()->reason);
        $this->assertSame($this->admin->id, $transfers->first()->transferred_by);
    }

    public function test_bulk_reassign_skips_leads_already_with_the_target(): void
    {
        Livewire::test(ListAssignedLeads::class)
            ->selectTableRecords([$this->untouchedLead->id, $this->hdfcLead->id])
            ->callAction(TestAction::make('reassign')->table()->bulk(), ['employee_id' => $this->ravi->id])
            ->assertNotified();

        $this->assertSame($this->ravi->id, $this->untouchedLead->fresh()->employee_id);
        $this->assertSame(0, $this->hdfcLead->fresh()->reassign_count);
        $this->assertSame(1, CustomerAssignmentTransfer::count());
    }

    public function test_callers_cannot_reassign(): void
    {
        $callerUser = User::factory()->create(['employee_id' => $this->asha->id]);
        $this->actingAs($callerUser);

        Livewire::test(ListAssignedLeads::class)
            ->assertActionHidden(TestAction::make('reassign')->table($this->interestedLead));
    }

    public function test_report_counts_remark_types_untouched_converted_and_work_status(): void
    {
        $this->untouchedLead->update(['converted_at' => null]);
        $this->hdfcLead->update(['converted_at' => now()]);

        $rows = LeadAssignmentReportsTable::applyReportScope(Employee::query(), null)->get()->keyBy('id');

        $asha = $rows[$this->asha->id];
        $this->assertSame(2, $asha->assigned_count);
        $this->assertSame(1, $asha->untouched_count);
        $this->assertSame(1, $asha->opened_count);
        $this->assertSame(1, $asha->followed_up_count);
        $this->assertSame(1, $asha->interested_count);
        $this->assertSame(1, $asha->pending_count);
        $this->assertSame(1, $asha->overdue_count);
        $this->assertSame('Active', LeadAssignmentReportsTable::workStatus($asha));
        $this->assertSame('Push to work 1 untouched lead(s)', LeadAssignmentReportsTable::suggestedAction($asha), 'Half the leads untouched outranks one overdue follow-up.');

        $ravi = $rows[$this->ravi->id];
        $this->assertSame(1, $ravi->converted_count);
        $this->assertSame(1, $ravi->untouched_count);
        $this->assertSame('Not Working', LeadAssignmentReportsTable::workStatus($ravi));
        $this->assertSame('Reassign 1 untouched lead(s)', LeadAssignmentReportsTable::suggestedAction($ravi));

        $this->assertFalse($rows->has($this->meera->id), 'Employees holding no leads are left out.');
    }

    public function test_report_template_filter_narrows_the_counts(): void
    {
        $rows = LeadAssignmentReportsTable::applyReportScope(Employee::query(), [
            'template' => ['values' => [$this->hdfcTemplate->id]],
        ])->get()->keyBy('id');

        $this->assertFalse($rows->has($this->asha->id));
        $this->assertSame(1, $rows[$this->ravi->id]->assigned_count);
    }

    public function test_report_page_renders_and_filters_employees_needing_attention(): void
    {
        Livewire::test(ListLeadAssignmentReports::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$this->asha, $this->ravi])
            ->filterTable('attention', 'not_working')
            ->assertCanSeeTableRecords([$this->ravi])
            ->assertCanNotSeeTableRecords([$this->asha])
            ->filterTable('attention', 'interested')
            ->assertCanSeeTableRecords([$this->asha])
            ->assertCanNotSeeTableRecords([$this->ravi]);
    }

    public function test_report_reassigns_an_employees_untouched_leads(): void
    {
        Livewire::test(ListLeadAssignmentReports::class)
            ->callAction(TestAction::make('reassignUntouched')->table($this->asha), ['employee_id' => $this->meera->id])
            ->assertNotified();

        $this->assertSame($this->meera->id, $this->untouchedLead->fresh()->employee_id);
        $this->assertSame($this->asha->id, $this->interestedLead->fresh()->employee_id, 'Worked leads stay with their owner.');
    }

    public function test_summary_widget_totals_the_leads_in_scope(): void
    {
        $widget = Livewire::test(LeadAssignmentSummary::class, ['tableFilters' => null])
            ->assertOk()
            ->assertSee('Leads Assigned')
            ->assertSee('Not Touched');

        /** @var Collection<int, CustomerAssignment> $leads */
        $leads = $widget->instance()->assignmentsQuery()->get();
        $this->assertCount(3, $leads);
    }
}
