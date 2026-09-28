<?php

namespace Tests\Feature;

use App\Filament\Widgets\DailyCommitmentStats;
use App\Models\Customer;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The eligibility dropdown has three values (eligible, not_eligible,
 * consent_pending). The overview must show a card for each so that the
 * three cards always add up to the total OTP card.
 */
class DailyCommitmentStatsEligibilityCardsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin']);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_eligible_not_eligible_and_consent_pending_cards_sum_to_total_otps(): void
    {
        Customer::factory()->count(4)->create(['eligibility_status' => 'eligible', 'created_at' => now()]);
        Customer::factory()->count(2)->create([
            'eligibility_status' => 'not_eligible',
            'eligibility_reason' => 'company_not_listed',
            'created_at' => now(),
        ]);
        Customer::factory()->count(3)->create(['eligibility_status' => 'consent_pending', 'created_at' => now()]);

        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        Livewire::test(DailyCommitmentStats::class)
            ->assertSee('Company OTPs')
            ->assertSee('Company Eligible')
            ->assertSee('Company Not Eligible')
            ->assertSee('Company Consent Pending')
            ->assertSeeInOrder(['Company OTPs', '9'])
            ->assertSeeInOrder(['Company Eligible', '4'])
            ->assertSeeInOrder(['Company Not Eligible', '2'])
            ->assertSeeInOrder(['Company Consent Pending', '3'])
            ->assertSee('33.3% awaiting consent');
    }

    public function test_consent_pending_card_shows_all_consented_when_none_pending(): void
    {
        Customer::factory()->count(2)->create(['eligibility_status' => 'eligible', 'created_at' => now()]);

        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        Livewire::test(DailyCommitmentStats::class)
            ->assertSee('Company Consent Pending')
            ->assertSee('ALL CONSENTED')
            ->assertSee('0% awaiting consent');
    }
}
