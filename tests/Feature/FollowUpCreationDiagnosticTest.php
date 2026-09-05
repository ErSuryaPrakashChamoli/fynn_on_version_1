<?php

namespace Tests\Feature;

use App\Filament\Resources\FollowUps\FollowUpResource;
use App\Filament\Resources\FollowUps\Pages\CreateFollowUp;
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

    public function test_creating_customer_follow_up_from_customer_page(): void
    {
        Role::firstOrCreate(['name' => 'Admin']);

        $employee = Employee::factory()->create([
            'designation' => Employee::DESIGNATION_CALLER,
        ]);

        $user = User::factory()->create(['employee_id' => $employee->id]);
        $this->actingAs($user);

        $customer = Customer::factory()->create();

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
            ->assertHasNoFormErrors();

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
