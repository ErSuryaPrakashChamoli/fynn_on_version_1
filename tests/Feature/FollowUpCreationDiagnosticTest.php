<?php

namespace Tests\Feature;

use App\Filament\Resources\FollowUps\FollowUpResource;
use App\Filament\Resources\FollowUps\Pages\CreateFollowUp;
use App\Filament\Resources\FollowUps\Pages\ListFollowUps;
use App\Filament\Widgets\AssignedLeadFollowUpCalendarWidget;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FollowUpCreationDiagnosticTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_customer_outside_the_users_reach_is_not_prefilled(): void
    {
        Role::firstOrCreate(['name' => 'Admin']);

        $employee = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER]);
        $stranger = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER]);

        $this->actingAs(User::factory()->create(['employee_id' => $employee->id]));

        $customer = Customer::factory()->create([
            'customer_name' => 'Someone Elses Customer',
            'assign_to' => $stranger->id,
            'employee_id' => $stranger->id,
        ]);

        Livewire::withQueryParams(['customer' => $customer->id])
            ->test(CreateFollowUp::class)
            ->assertFormSet(['customer_id' => null])
            ->assertDontSee('Someone Elses Customer');
    }

    public function test_creating_customer_follow_up_from_customer_page(): void
    {
        Role::firstOrCreate(['name' => 'Admin']);

        $employee = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
        ]);

        $user = User::factory()->create(['employee_id' => $employee->id]);
        $this->actingAs($user);

        $customer = Customer::factory()->create([
            'assign_to' => $employee->id,
            'employee_id' => $employee->id,
        ]);

        Livewire::withQueryParams(['customer' => $customer->id])
            ->test(CreateFollowUp::class)
            ->assertFormSet(['customer_id' => $customer->id])
            ->fillForm([
                'follow_up_type' => 'Call',
                'status' => 'Awaiting Low ROI',
                'next_follow_up_date' => now()->addWeek()->format('Y-m-d H:i'),
                'remarks' => 'diagnostic test',
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(FollowUpResource::getUrl('index'));

        $this->assertDatabaseHas('follow_ups', [
            'customer_id' => $customer->id,
            'employee_id' => $employee->id,
            'remarks' => 'diagnostic test',
        ]);

        $followUp = FollowUp::where('customer_id', $customer->id)->firstOrFail();

        $this->assertTrue(
            FollowUpResource::getEloquentQuery()->whereKey($followUp->id)->exists(),
            'Customer follow-up should be visible on "My Customer Follow-ups" / its calendar.'
        );
    }

    public function test_status_is_not_preselected_and_must_be_chosen(): void
    {
        Role::firstOrCreate(['name' => 'Admin']);

        $employee = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER]);
        $this->actingAs(User::factory()->create(['employee_id' => $employee->id]));

        $customer = Customer::factory()->create([
            'assign_to' => $employee->id,
            'employee_id' => $employee->id,
        ]);

        Livewire::withQueryParams(['customer' => $customer->id])
            ->test(CreateFollowUp::class)
            ->assertSchemaStateSet(['status' => null])
            ->fillForm([
                'follow_up_type' => 'Call',
                'next_follow_up_date' => now()->addWeek()->format('Y-m-d H:i'),
                'remarks' => 'no status picked',
            ])
            ->call('create')
            ->assertHasFormErrors(['status' => 'required']);

        $this->assertDatabaseMissing('follow_ups', ['customer_id' => $customer->id]);
    }

    public function test_a_new_follow_up_opens_with_the_previous_remarks(): void
    {
        Role::firstOrCreate(['name' => 'Admin']);

        $employee = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER]);
        $this->actingAs(User::factory()->create(['employee_id' => $employee->id]));

        $customer = Customer::factory()->create([
            'assign_to' => $employee->id,
            'employee_id' => $employee->id,
        ]);

        foreach (['first call, asked for documents', 'documents received, awaiting ROI'] as $remarks) {
            FollowUp::create([
                'customer_id' => $customer->id,
                'employee_id' => $employee->id,
                'follow_up_type' => 'Call',
                'status' => 'Awaiting Low ROI',
                'next_follow_up_date' => now()->addDay(),
                'remarks' => $remarks,
            ]);
        }

        Livewire::withQueryParams(['customer' => $customer->id])
            ->test(CreateFollowUp::class)
            ->assertSchemaStateSet(['remarks' => 'documents received, awaiting ROI'])
            ->fillForm([
                'follow_up_type' => 'WhatsApp',
                'status' => 'On Hold',
                'next_follow_up_date' => now()->addWeek()->format('Y-m-d H:i'),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(
            'documents received, awaiting ROI',
            FollowUp::where('customer_id', $customer->id)->latest('id')->value('remarks'),
        );
    }

    public function test_a_first_follow_up_opens_with_empty_remarks(): void
    {
        Role::firstOrCreate(['name' => 'Admin']);

        $employee = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER]);
        $this->actingAs(User::factory()->create(['employee_id' => $employee->id]));

        $customer = Customer::factory()->create([
            'assign_to' => $employee->id,
            'employee_id' => $employee->id,
        ]);

        Livewire::withQueryParams(['customer' => $customer->id])
            ->test(CreateFollowUp::class)
            ->assertSchemaStateSet(['remarks' => null]);
    }

    public function test_the_follow_up_listing_shows_the_remarks(): void
    {
        Role::firstOrCreate(['name' => 'Admin']);

        $employee = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER]);
        $this->actingAs(User::factory()->create(['employee_id' => $employee->id]));

        $customer = Customer::factory()->create([
            'assign_to' => $employee->id,
            'employee_id' => $employee->id,
        ]);

        $followUp = FollowUp::create([
            'customer_id' => $customer->id,
            'employee_id' => $employee->id,
            'follow_up_type' => 'WhatsApp',
            'status' => 'On Hold',
            'next_follow_up_date' => now()->addDay(),
            'remarks' => 'Customer asked to call back after salary credit',
        ]);

        Livewire::test(ListFollowUps::class)
            ->assertCanSeeTableRecords([$followUp])
            ->assertTableColumnExists('remarks')
            ->assertSee('Customer asked to call back after salary credit');
    }

    public function test_editing_a_lead_logs_a_follow_up_visible_on_the_lead_calendar(): void
    {
        Role::firstOrCreate(['name' => 'Admin']);

        $employee = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
        ]);

        $user = User::factory()->create(['employee_id' => $employee->id]);
        $this->actingAs($user);

        $lead = Lead::create([
            'employee_id' => $employee->id,
            'customer_name' => 'New Test',
            'mobile_no' => '8989999999',
            'follow_up_date' => now()->toDateString(),
            'follow_up_type' => 'Call',
            'status' => 'Pending',
            'remarks' => 'initial contact',
        ]);

        $this->assertDatabaseHas('follow_ups', [
            'lead_id' => $lead->id,
            'employee_id' => $employee->id,
            'remarks' => 'initial contact',
        ]);

        $lead->update([
            'status' => 'Interested',
            'next_follow_up_date' => now()->addDays(3),
            'remarks' => 'follow-up after edit',
        ]);

        $this->assertDatabaseHas('follow_ups', [
            'lead_id' => $lead->id,
            'remarks' => 'follow-up after edit',
        ]);

        $this->assertSame(2, FollowUp::where('lead_id', $lead->id)->count());

        // Not shown on the Customer follow-up calendar.
        $this->assertFalse(
            FollowUpResource::getEloquentQuery()->where('lead_id', $lead->id)->exists()
        );

        // Shown on the Lead Follow-Up Calendar.
        $nextFollowUp = FollowUp::where('lead_id', $lead->id)->latest('id')->first();

        $events = (new AssignedLeadFollowUpCalendarWidget)->fetchEvents([
            'start' => now()->startOfMonth()->toDateString(),
            'end' => now()->endOfMonth()->addMonth()->toDateString(),
        ]);

        $dates = collect($events)->pluck('extendedProps.date');

        $this->assertTrue(
            $dates->contains($nextFollowUp->next_follow_up_date->toDateString()),
            'Lead follow-up should appear on the Lead Follow-Up Calendar.'
        );
    }
}
