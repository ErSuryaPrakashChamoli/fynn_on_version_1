<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Resources\Employees\EmployeeResource;
use App\Services\ReportingLineService;
use Filament\Resources\Pages\CreateRecord;

class CreateEmployee extends CreateRecord
{
    protected static string $resource = EmployeeResource::class;

    /** The employee and their joining history row land together. */
    protected ?bool $hasDatabaseTransactions = true;

    /**
     * Every reporting column is derived from the one boss chosen in
     * Reports To, so the joining history records the full line — see
     * ReportingLineService.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $bossId = filled($data['reports_to'] ?? null) ? (int) $data['reports_to'] : null;

        unset($data['reports_to']);

        return [
            ...$data,
            ...app(ReportingLineService::class)->columnsUnder($bossId),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
