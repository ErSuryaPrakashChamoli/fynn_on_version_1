<?php

namespace Tests\Feature;

use App\Filament\Widgets\CustomerStats;
use App\Models\Customer;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Guards against the journey_status === 'finalized' regression: that value
 * never exists in the journey_status column, so Completed Journey always
 * read 0 and Pending Journey always read 100%. Completion is tracked by the
 * disbursal_finalized boolean instead.
 */
class CustomerStatsJourneyCalculationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin']);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_completed_and_pending_journey_counts_use_disbursal_finalized(): void
    {
        Customer::factory()->count(3)->create(['disbursal_finalized' => true]);
        Customer::factory()->count(2)->create(['disbursal_finalized' => false]);

        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        Livewire::test(CustomerStats::class)
            ->assertSee('5') // total customers
            ->assertSee('3') // completed journey
            ->assertSee('2'); // pending journey
    }

    public function test_no_customers_ever_have_a_finalized_journey_status(): void
    {
        Customer::factory()->count(5)->create();

        $this->assertSame(
            0,
            Customer::query()->where('journey_status', 'finalized')->count()
        );
    }
}
