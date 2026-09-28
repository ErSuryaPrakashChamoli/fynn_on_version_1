<?php

namespace Tests\Feature;

use App\Filament\Widgets\IncentiveStats;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\User;
use App\Services\AchievementCalculatorService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Cashback, subvention and docking are each reported in two parts: loans
 * sanctioned by BFL Prime / Growth / SOL, and loans from every other bank
 * (including BFL RSL), because the two groups follow different policies.
 */
class IncentiveDeductionSplitTest extends TestCase
{
    use RefreshDatabase;

    private Employee $caller;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin']);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->caller = Employee::factory()->create(['designation' => Employee::DESIGNATION_CALLER]);

        foreach ([
            ['BFL Prime', 1000, 100, '10'],
            ['bfl-sol', 2000, 200, '20'],      // normalised like the deduction formula
            ['BFL Growth', 4000, 400, '40'],
            ['BFL RSL', 8000, 800, '80'],      // not one of the three: other
            ['HDFC Bank', 16000, 1600, '160'],
            [null, 32000, 3200, '320'],        // no bank recorded: other
        ] as [$bank, $cashback, $subvention, $docking]) {
            Customer::factory()->create([
                'employee_id' => $this->caller->id,
                'sanctioned_bank' => $bank,
                'sanctioned_loan_amount' => 1000000,
                'cashback' => $cashback,
                'subvention' => $subvention,
                'docking' => $docking,
                'disbursal_date' => now(),
            ]);
        }
    }

    public function test_the_calculator_splits_each_deduction_between_the_three_bfl_products_and_the_rest(): void
    {
        $performance = (new AchievementCalculatorService)->getPerformance($this->caller);

        $this->assertSame(7000.0, $performance['cashback_bfl']);
        $this->assertSame(56000.0, $performance['cashback_other']);
        $this->assertSame(63000.0, $performance['cashback']);

        $this->assertSame(700.0, $performance['subvention_bfl']);
        $this->assertSame(5600.0, $performance['subvention_other']);

        $this->assertSame(70.0, $performance['docking_bfl']);
        $this->assertSame(560.0, $performance['docking_other']);
        $this->assertSame(630.0, $performance['docking']);
    }

    public function test_the_widget_shows_both_parts_on_each_card_with_the_total_in_the_description(): void
    {
        $user = User::factory()->create(['employee_id' => $this->caller->id]);
        $this->actingAs($user);

        Livewire::test(IncentiveStats::class)
            ->assertOk()
            // Rendered as markup, not escaped text.
            ->assertSeeHtml('<span class="incentive-split">')
            ->assertSee('BFL Prime · Growth · SOL')
            ->assertSee('Other banks')
            ->assertSee('₹7,000')
            ->assertSee('₹56,000')
            ->assertSee('Total ₹63,000')
            ->assertSee('₹700')
            ->assertSee('₹5,600')
            ->assertSee('Total docking ₹630');
    }
}
