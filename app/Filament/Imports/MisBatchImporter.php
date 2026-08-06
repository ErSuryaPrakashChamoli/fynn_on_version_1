<?php

namespace App\Filament\Imports;

use App\Models\MisBatch;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Support\Number;

class MisBatchImporter extends Importer
{
    protected static ?string $model = MisBatch::class;

    public static function getColumns(): array
    {
        return [
            //
        ];
    }

    public function resolveRecord(): MisBatch
    {
        return new MisBatch();
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Your mis batch import has completed and ' . Number::format($import->successful_rows) . ' ' . str('row')->plural($import->successful_rows) . ' imported.';

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' ' . Number::format($failedRowsCount) . ' ' . str('row')->plural($failedRowsCount) . ' failed to import.';
        }

        return $body;
    }
}
