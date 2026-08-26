<?php

namespace App\Services\Settlement;

use App\Models\CustomerSettlement;
use Illuminate\Validation\ValidationException;

class MisSettlementImporterService
{
    public function resolveByLan(string $lan): CustomerSettlement
    {
        $lan = trim($lan);

        $settlements = CustomerSettlement::query()
            ->where('mis_lan_no', $lan)
            ->orWhereHas('customer', fn ($query) => $query->where('lan_no', $lan))
            ->get();

        if ($settlements->isEmpty()) {
            throw ValidationException::withMessages([
                'lan' => "No Customer Settlement found for LAN: {$lan}",
            ]);
        }

        if ($settlements->count() > 1) {
            throw ValidationException::withMessages([
                'lan' => "LAN {$lan} is ambiguous: it matches {$settlements->count()} customer settlements. Resolve the duplicate LAN before importing.",
            ]);
        }

        return $settlements->first();
    }
}
