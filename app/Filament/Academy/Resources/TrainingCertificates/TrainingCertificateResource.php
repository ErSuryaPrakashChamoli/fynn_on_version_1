<?php

namespace App\Filament\Academy\Resources\TrainingCertificates;

use App\Filament\Academy\Resources\Concerns\TrainerResource;
use App\Filament\Academy\Resources\TrainingCertificates\Pages\ListTrainingCertificates;
use App\Filament\Academy\Resources\TrainingCertificates\Tables\TrainingCertificatesTable;
use App\Models\Training\TrainingCertificate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Issued certificates. Read-only by design — a certificate is created
 * by CertificateService (which allocates the number and verifies
 * completion) and is never hand-written.
 */
class TrainingCertificateResource extends Resource
{
    use TrainerResource;

    protected static ?string $model = TrainingCertificate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrophy;

    protected static string|UnitEnum|null $navigationGroup = 'Assessment';

    protected static ?string $navigationLabel = 'Certificates';

    protected static ?string $slug = 'certificates';

    protected static ?int $navigationSort = 2;

    public static function table(Table $table): Table
    {
        return TrainingCertificatesTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTrainingCertificates::route('/'),
        ];
    }
}
