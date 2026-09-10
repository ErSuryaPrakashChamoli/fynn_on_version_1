<?php

namespace App\Filament\Academy\Resources\TrainingEnrollments;

use App\Filament\Academy\Resources\Concerns\TrainerResource;
use App\Filament\Academy\Resources\TrainingEnrollments\Pages\ListTrainingEnrollments;
use App\Filament\Academy\Resources\TrainingEnrollments\Tables\TrainingEnrollmentsTable;
use App\Models\Training\TrainingEnrollment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The trainer's roster: every trainee/course pairing, with the actions
 * that act on one — remarks, and certificate issue.
 *
 * Read-only as a resource (no create/edit pages): enrollments are made
 * from inside a batch, where the trainee and the tenant are already
 * established, rather than assembled field by field here.
 */
class TrainingEnrollmentResource extends Resource
{
    use TrainerResource;

    protected static ?string $model = TrainingEnrollment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Delivery';

    protected static ?string $navigationLabel = 'Trainees';

    protected static ?string $slug = 'trainees';

    protected static ?int $navigationSort = 2;

    public static function table(Table $table): Table
    {
        return TrainingEnrollmentsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTrainingEnrollments::route('/'),
        ];
    }
}
