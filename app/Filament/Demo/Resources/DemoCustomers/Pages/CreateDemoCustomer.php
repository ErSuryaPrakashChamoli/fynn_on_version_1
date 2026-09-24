<?php

namespace App\Filament\Demo\Resources\DemoCustomers\Pages;

use App\Filament\Demo\Resources\DemoCustomers\DemoCustomerResource;
use App\Models\Demo\DemoCustomer;
use Filament\Resources\Pages\CreateRecord;

class CreateDemoCustomer extends CreateRecord
{
    protected static string $resource = DemoCustomerResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['customer_code'] = 'CU'.str_pad((string) ((int) DemoCustomer::query()->max('id') + 200001), 6, '0', STR_PAD_LEFT);

        return $data;
    }
}
