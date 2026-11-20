<?php

namespace App\Filament\Resources\CustomerCashbackRequests\Pages;

use App\Filament\Resources\CustomerCashbackRequests\CustomerCashbackRequestActions;
use App\Filament\Resources\CustomerCashbackRequests\CustomerCashbackRequestResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewCustomerCashbackRequest extends ViewRecord
{
    protected static string $resource = CustomerCashbackRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...CustomerCashbackRequestActions::all(after: fn () => $this->record->refresh()),
            Action::make('printVoucher')
                ->label('Print voucher')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->alpineClickHandler('window.print()'),
        ];
    }
}
