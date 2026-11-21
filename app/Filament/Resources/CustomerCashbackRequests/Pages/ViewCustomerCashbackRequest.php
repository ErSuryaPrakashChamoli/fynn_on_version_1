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
            // Record payment sits on the voucher itself (CustomerCashbackRequestInfolist).
            CustomerCashbackRequestActions::approve(after: fn () => $this->record->refresh()),
            CustomerCashbackRequestActions::reject(after: fn () => $this->record->refresh()),
            CustomerCashbackRequestActions::retrieve(after: fn () => $this->record->refresh()),
            CustomerCashbackRequestActions::changeAmount(after: fn () => $this->record->refresh()),
            CustomerCashbackRequestActions::revise(),
            Action::make('printVoucher')
                ->label('Print voucher')
                ->icon('heroicon-o-printer')
                ->color('gray')
                ->alpineClickHandler('window.print()'),
        ];
    }
}
