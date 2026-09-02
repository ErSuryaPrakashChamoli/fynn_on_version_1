<?php

namespace Tests\Feature;

use App\Filament\Resources\AccountVerifications\AccountVerificationResource;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\CustomerSettlements\CustomerSettlementResource;
use App\Models\Customer;
use App\Models\CustomerSettlement;
use App\Models\User;
use App\Services\CustomerJourneyService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The global month selector must scope customers by disbursal_date, not by
 * created_at/approval_date: a customer whose journey is still open (no
 * disbursal_date yet) stays visible under every month, while a disbursed
 * customer only appears under the month it was actually disbursed in.
 * Customer Settlement and MIS Verification follow the same disbursal_date
 * rule rather than mis_disbursal_date/updated_at.
 */
class CustomerDisbursalDateFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin']);
        Role::firstOrCreate(['name' => 'Accounts']);
        Role::firstOrCreate(['name' => 'MIS']);

        Carbon::setTestNow(Carbon::create(2026, 8, 27));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole(['Admin', 'Accounts', 'MIS']);
        $this->actingAs($admin);

        return $admin;
    }

    public function test_sanction_sets_disbursal_date_only_when_disbursed(): void
    {
        $customer = Customer::factory()->create(['journey_status' => 'approved']);

        $disbursed = CustomerJourneyService::sanction($customer, [
            'disbursal_status' => 'disbursed',
            'disbursal_date' => '2026-08-15',
            'sanctioned_remarks' => 'ok',
        ]);

        $this->assertSame('2026-08-15', $disbursed->disbursal_date->toDateString());

        $customer2 = Customer::factory()->create(['journey_status' => 'approved']);

        $carriedForward = CustomerJourneyService::sanction($customer2, [
            'disbursal_status' => 'carry_forward',
            'carry_forward_date' => '2026-09-01',
            'sanctioned_remarks' => 'ok',
        ]);

        $this->assertNull($carriedForward->disbursal_date);
    }

    public function test_customers_list_always_shows_open_journeys_but_scopes_disbursed_ones_to_the_selected_month(): void
    {
        $this->actingAsAdmin();

        $stillInPipeline = Customer::factory()->create([
            'created_at' => Carbon::create(2026, 1, 5),
            'disbursal_status' => null,
            'disbursal_date' => null,
        ]);

        $disbursedInAugust = Customer::factory()->create([
            'created_at' => Carbon::create(2026, 1, 5),
            'disbursal_status' => 'disbursed',
            'disbursal_date' => Carbon::create(2026, 8, 10),
        ]);

        $disbursedInJuly = Customer::factory()->create([
            'created_at' => Carbon::create(2026, 8, 20),
            'disbursal_status' => 'disbursed',
            'disbursal_date' => Carbon::create(2026, 7, 1),
        ]);

        Livewire::test(ListCustomers::class)
            ->assertCanSeeTableRecords([$stillInPipeline, $disbursedInAugust])
            ->assertCanNotSeeTableRecords([$disbursedInJuly]);
    }

    public function test_mis_verification_scopes_disbursed_customers_by_disbursal_date(): void
    {
        $this->actingAsAdmin();

        $disbursedInAugust = Customer::factory()->create([
            'disbursal_status' => 'disbursed',
            'disbursal_date' => Carbon::create(2026, 8, 10),
        ]);

        $disbursedInJuly = Customer::factory()->create([
            'disbursal_status' => 'disbursed',
            'disbursal_date' => Carbon::create(2026, 7, 10),
        ]);

        $ids = AccountVerificationResource::getEloquentQuery()->pluck('id');

        $this->assertTrue($ids->contains($disbursedInAugust->id));
        $this->assertFalse($ids->contains($disbursedInJuly->id));
    }

    public function test_customer_settlement_scopes_by_the_customers_disbursal_date_not_mis_disbursal_date(): void
    {
        $this->actingAsAdmin();

        $customerDisbursedInAugust = Customer::factory()->create([
            'disbursal_status' => 'disbursed',
            'disbursal_date' => Carbon::create(2026, 8, 10),
        ]);

        $settlementInAugust = CustomerSettlement::create([
            'settlement_no' => 'STL-AUG',
            'customer_id' => $customerDisbursedInAugust->id,
            'version' => 1,
            // Bank's own reported date is deliberately in a different
            // month, to prove the filter no longer keys off this column.
            'mis_disbursal_date' => Carbon::create(2026, 7, 1),
        ]);

        $customerDisbursedInJuly = Customer::factory()->create([
            'disbursal_status' => 'disbursed',
            'disbursal_date' => Carbon::create(2026, 7, 10),
        ]);

        $settlementInJuly = CustomerSettlement::create([
            'settlement_no' => 'STL-JUL',
            'customer_id' => $customerDisbursedInJuly->id,
            'version' => 1,
            'mis_disbursal_date' => Carbon::create(2026, 8, 1),
        ]);

        $ids = CustomerSettlementResource::getEloquentQuery()->pluck('id');

        $this->assertTrue($ids->contains($settlementInAugust->id));
        $this->assertFalse($ids->contains($settlementInJuly->id));
    }
}
