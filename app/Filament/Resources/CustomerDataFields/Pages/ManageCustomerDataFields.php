<?php

namespace App\Filament\Resources\CustomerDataFields\Pages;

use App\Filament\Resources\CustomerDataFields\CustomerDataFieldResource;
use App\Models\CustomerDataField;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCustomerDataFields extends ManageRecords
{
    protected static string $resource = CustomerDataFieldResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Add field')
                ->mutateDataUsing(function (array $data): array {
                    $data['key'] = CustomerDataField::uniqueKeyForLabel((string) $data['label']);
                    $data['sort_order'] = (int) CustomerDataField::query()->max('sort_order') + 1;
                    $data['created_by'] = auth()->id();

                    return $data;
                }),
        ];
    }
}
