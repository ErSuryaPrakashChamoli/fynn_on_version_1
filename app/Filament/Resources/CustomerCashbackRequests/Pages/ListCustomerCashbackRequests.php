<?php

namespace App\Filament\Resources\CustomerCashbackRequests\Pages;

use App\Filament\Concerns\HasColumnHeaderFilters;
use App\Filament\Resources\CustomerCashbackRequests\CustomerCashbackRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCustomerCashbackRequests extends ListRecords
{
    use HasColumnHeaderFilters;

    protected static string $resource = CustomerCashbackRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Fill cashback form')
                ->icon('heroicon-o-receipt-refund'),
        ];
    }
}
