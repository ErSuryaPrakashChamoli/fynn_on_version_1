<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Employee;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCustomers extends ListRecords
{
    protected static string $resource = CustomerResource::class;

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
        ];
    }
}
