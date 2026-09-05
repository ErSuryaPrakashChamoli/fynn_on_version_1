<?php

namespace App\Filament\Resources\MonthlyCommitmentTargets\Pages;

use App\Filament\Resources\MonthlyCommitmentTargets\MonthlyCommitmentTargetResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditMonthlyCommitmentTarget extends EditRecord
{
    protected static string $resource = MonthlyCommitmentTargetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
