<?php

namespace App\Filament\Resources\MonthlyCommitmentTargets\Pages;

use App\Filament\Resources\MonthlyCommitmentTargets\MonthlyCommitmentTargetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMonthlyCommitmentTargets extends ListRecords
{
    protected static string $resource = MonthlyCommitmentTargetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
