<?php

namespace Tests\Feature;

use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CustomerActivityTimelineRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_changed_field_is_shown_once_in_the_timeline(): void
    {
        Role::firstOrCreate(['name' => 'Admin']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $employee = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER]);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        $customer = Customer::factory()->create([
            'assign_to' => $employee->id,
            'employee_id' => $employee->id,
            'eligibility_status' => 'consent_pending',
            'eligibility_reason' => null,
        ]);
        $customer->update(['eligibility_status' => 'not_eligible', 'eligibility_reason' => 'company_not_listed']);

        $this->actingAs($admin);

        $html = Livewire::test(ViewCustomer::class, ['record' => $customer->id])->html();

        $this->assertStringContainsString('Customer file created', $html);
        $this->assertSame(1, substr_count($html, '<strong>Eligibility Status</strong>'));
        $this->assertSame(1, substr_count($html, '<strong>Eligibility Reason</strong>'));
        $this->assertStringNotContainsString('<strong>Updated At</strong>', $html);
    }
}
