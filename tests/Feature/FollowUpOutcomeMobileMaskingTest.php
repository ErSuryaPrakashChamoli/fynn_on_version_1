<?php

namespace Tests\Feature;

use App\Filament\Pages\CustomerFollowUpOutcomes;
use App\Filament\Pages\LeadFollowUpOutcomes;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\User;
use App\Support\FollowUpRecordUrl;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Lead and Customer Follow-up Outcomes listings show only the last four
 * digits of a prospect's mobile number; clicking the row still opens the
 * record, where the name and full number are shown.
 */
class FollowUpOutcomeMobileMaskingTest extends TestCase
{
    use RefreshDatabase;

    private Employee $caller;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->travelTo(Carbon::parse('2026-09-16 12:00:00'));

        $this->caller = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER, 'exit_status' => 'no']);

        $this->actingAs(User::factory()->create(['employee_id' => $this->caller->id]));
    }

    public function test_the_lead_listing_masks_the_mobile_and_the_row_still_opens_the_lead(): void
    {
        $missed = $this->leadFollowUp('Masked Lead', '9876543210');

        $component = Livewire::test(LeadFollowUpOutcomes::class)
            ->assertCanSeeTableRecords([$missed])
            ->assertTableColumnFormattedStateSet('mobile', 'XXXXXX3210', $missed)
            ->assertSee('Masked Lead')
            ->assertDontSee('9876543210');

        $rowUrl = $component->instance()->getTable()->getRecordUrl($missed);

        $this->assertNotNull($rowUrl);
        $this->assertSame(FollowUpRecordUrl::for($missed), $rowUrl);
    }

    public function test_the_customer_listing_masks_the_mobile(): void
    {
        $customer = Customer::factory()->create([
            'customer_name' => 'Masked Customer',
            'mobile_no' => '9123456789',
            'assign_to' => $this->caller->id,
            'employee_id' => $this->caller->id,
        ]);

        $missed = FollowUp::create([
            'customer_id' => $customer->id,
            'employee_id' => $this->caller->id,
            'follow_up_type' => 'Call',
            'status' => 'Call Back',
            'remarks' => 'logged',
            'next_follow_up_date' => Carbon::parse('2026-09-10 15:00'),
        ]);
        $missed->forceFill(['created_at' => Carbon::parse('2026-09-08 09:00')])->saveQuietly();

        Livewire::test(CustomerFollowUpOutcomes::class)
            ->assertCanSeeTableRecords([$missed])
            ->assertTableColumnFormattedStateSet('mobile', 'XXXXXX6789', $missed)
            ->assertDontSee('9123456789');
    }

    public function test_searching_by_the_full_mobile_still_finds_the_prospect(): void
    {
        $wanted = $this->leadFollowUp('Wanted Lead', '9876543210');
        $other = $this->leadFollowUp('Other Lead', '9000011111');

        Livewire::test(LeadFollowUpOutcomes::class)
            ->searchTable('9876543210')
            ->assertCanSeeTableRecords([$wanted])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_a_number_too_short_to_mask_is_shown_as_it_is(): void
    {
        $short = $this->leadFollowUp('Short Number', '123');

        Livewire::test(LeadFollowUpOutcomes::class)
            ->assertTableColumnFormattedStateSet('mobile', '123', $short);
    }

    /** A raw lead logs its own follow-up row when it is created. */
    private function leadFollowUp(string $name, string $mobile): FollowUp
    {
        $lead = Lead::create([
            'employee_id' => $this->caller->id,
            'customer_name' => $name,
            'mobile_no' => $mobile,
            'follow_up_type' => 'Call',
            'status' => 'Call Back',
            'remarks' => 'call later',
            'next_follow_up_date' => Carbon::parse('2026-09-10 11:00'),
        ]);

        return FollowUp::query()->where('lead_id', $lead->id)->latest('id')->firstOrFail();
    }
}
