<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Employee;
use Override;

class DirectCreateCustomer extends CreateCustomer
{
    protected static string $resource = CustomerResource::class;

    public static function getNavigationLabel(): string
    {
        return 'Direct Create Customer';
    }

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-user-plus';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Customers';
    }

    #[Override]
    public static function canAccess(array $parameters = []): bool
    {
        // return parent::canAccess($parameters);
        $employee = auth()->user()?->employee;

        return $employee
            && in_array($employee->designation, [
                Employee::DESIGNATION_TEAM_LEADER,
                Employee::DESIGNATION_MANAGER,
                Employee::DESIGNATION_CLUSTER,
            ]);
    }



    public function getTitle(): string
    {
        return 'Direct Create Customer';
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = parent::mutateFormDataBeforeCreate($data);

        $data['employee_id'] = auth()->user()->employee_id;
        $data['assign_to'] = auth()->user()->employee_id;

        return $data;
    }
}
