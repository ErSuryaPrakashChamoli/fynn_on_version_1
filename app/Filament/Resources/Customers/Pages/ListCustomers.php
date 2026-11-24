<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Concerns\HasColumnHeaderFilters;
use App\Filament\Resources\CustomerCashbackRequests\CustomerCashbackRequestResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Employee;
use App\Support\CustomerListingPeriod;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Livewire\Attributes\Url;

class ListCustomers extends ListRecords
{
    use HasColumnHeaderFilters;

    protected static string $resource = CustomerResource::class;

    /**
     * The journey status picked beside "Export Customers" ("all" or a
     * customers.journey_status); the listing and its export both follow
     * it, together with the topbar period (CustomerListingPeriod).
     */
    #[Url(as: 'status')]
    public string $customerStatus = CustomerListingPeriod::ALL_STATUSES;

    protected function getHeaderActions(): array
    {
        return [

            /*
            |--------------------------------------------------------------------------
            | Normal Customer
            |--------------------------------------------------------------------------
            | Only Caller creates customer through the normal flow.
            */
            CreateAction::make()
                ->label('New Customer')
                ->icon('heroicon-o-plus')
                ->visible(function (): bool {
                    return auth()->user()->employee?->designation
                        === Employee::DESIGNATION_CALLER;
                }),

            /*
            |--------------------------------------------------------------------------
            | Direct Customer
            |--------------------------------------------------------------------------
            | Manager and Team Leader can create customers directly.
            */
            Action::make('createDirectCustomer')
                ->label('Direct Customer')
                ->icon('heroicon-o-user-plus')
                ->color('success')
                ->url(fn (): string => CustomerResource::getUrl('create', [
                    'direct' => 1,
                ]))
                ->visible(function (): bool {
                    return in_array(
                        auth()->user()->employee?->designation,
                        [
                            Employee::DESIGNATION_TEAM_LEADER,
                            Employee::DESIGNATION_MANAGER,
                        ],
                        true
                    );
                }),

            /*
            |--------------------------------------------------------------------------
            | Cashback Form
            |--------------------------------------------------------------------------
            | A new cashback on any case — including one punched in on an
            | earlier day or month: opens the form, where the case is picked.
            */
            Action::make('fillCashbackForm')
                ->label('Cashback Form')
                ->icon('heroicon-o-receipt-refund')
                ->color('gray')
                ->url(fn (): string => CustomerCashbackRequestResource::getUrl('create'))
                ->visible(fn (): bool => CustomerCashbackRequestResource::canCreate()),
        ];
    }
}
