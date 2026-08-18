<?php

namespace App\Services\Settlement;

use App\Models\CustomerSettlement;
use Illuminate\Validation\ValidationException;

class MisSettlementImporterService
{
    public function resolveByLan(string $lan): CustomerSettlement
    {
        $lan = trim($lan);

        $settlement = CustomerSettlement::query()
            ->where('mis_lan_no', $lan)
            ->orWhereHas('customer', fn ($query) => $query->where('lan_no', $lan))
            ->first();

        if (! $settlement) {
            throw ValidationException::withMessages([
                'lan' => "No Customer Settlement found for LAN: {$lan}",
            ]);
        }

        return $settlement;
    }
}
