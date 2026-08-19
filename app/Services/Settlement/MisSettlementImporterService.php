<?php

namespace App\Services\Settlement;

use App\Models\CustomerSettlement;
use Illuminate\Validation\ValidationException;

class MisSettlementImporterService
{
    public function resolveByLan(string $lan): ?CustomerSettlement
    {
        $lan = trim($lan);

        $settlement = CustomerSettlement::query()
            ->where('mis_lan_no', $lan)
            ->orWhereHas('customer', fn($query) => $query->where('lan_no', $lan))
            ->first();

        if (! $settlement) {
            throw ValidationException::withMessages([
                'lan' => "No Customer Settlement found for LAN: {$lan}",
            ]);
        }

        return $settlement;
    }


    public function resolveRecord(): ?CustomerSettlement
    {
        $lan = trim((string) ($this->data['mis_lan_no'] ?? ''));

        if ($lan === '') {
            throw new \RuntimeException('LAN is required.');
        }

        $record = app(MisSettlementImporterService::class)
            ->resolveByLan($lan);

        if (! $record) {
            throw new \RuntimeException(
                "LAN {$lan} was not found in Customer Settlement."
            );
        }

        return $record;
    }
}
