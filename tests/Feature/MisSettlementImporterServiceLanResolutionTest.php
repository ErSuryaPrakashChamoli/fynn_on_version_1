<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerSettlement;
use App\Services\Settlement\MisSettlementImporterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MisSettlementImporterServiceLanResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_the_settlement_for_a_unique_lan(): void
    {
        $customer = Customer::factory()->create(['lan_no' => 'LAN-UNIQUE-1']);

        $settlement = $this->makeSettlement($customer, 'SET-UNIQUE-1');

        $resolved = (new MisSettlementImporterService)->resolveByLan('LAN-UNIQUE-1');

        $this->assertTrue($resolved->is($settlement));
    }

    public function test_throws_when_lan_is_not_found(): void
    {
        $this->expectException(ValidationException::class);

        (new MisSettlementImporterService)->resolveByLan('LAN-DOES-NOT-EXIST');
    }

    public function test_throws_instead_of_silently_picking_when_lan_is_ambiguous(): void
    {
        $customerA = Customer::factory()->create(['lan_no' => 'LAN-DUPLICATE']);
        $customerB = Customer::factory()->create(['lan_no' => 'LAN-DUPLICATE']);

        $this->makeSettlement($customerA, 'SET-DUP-1');
        $this->makeSettlement($customerB, 'SET-DUP-2');

        $this->expectException(ValidationException::class);

        (new MisSettlementImporterService)->resolveByLan('LAN-DUPLICATE');
    }

    private function makeSettlement(Customer $customer, string $settlementNo): CustomerSettlement
    {
        return CustomerSettlement::create([
            'settlement_no' => $settlementNo,
            'customer_id' => $customer->id,
            'version' => 1,
            'status' => 'pending',
        ]);
    }
}
