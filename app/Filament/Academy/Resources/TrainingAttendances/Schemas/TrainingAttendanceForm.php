<?php

namespace App\Filament\Academy\Resources\TrainingAttendances\Schemas;

use App\Models\Training\TrainingBatch;
use App\Support\Portal\PortalContext;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class TrainingAttendanceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(2)->schema([
                Select::make('training_batch_id')
                    ->label('Batch')
                    ->options(fn (): array => TrainingBatch::query()
                        ->where('tenant_id', app(PortalContext::class)->tenantId())
                        ->orderByDesc('starts_on')
                        ->pluck('name', 'id')
                        ->all())
                    ->required()
                    ->preload()
                    ->live()
                    ->columnSpan(1),

                /*
                 * The trainee list is derived from the chosen batch, so
                 * attendance can only ever be recorded for someone who
                 * is actually in it.
                 */
                Select::make('trainee_id')
                    ->label('Trainee')
                    ->options(fn (Get $get): array => static::traineeOptions($get('training_batch_id')))
                    ->required()
                    ->preload()
                    ->columnSpan(1),

                DatePicker::make('attendance_date')
                    ->native(false)
                    ->default(now())
                    ->required()
                    ->columnSpan(1),

                Select::make('status')
                    ->options([
                        'present' => 'Present',
                        'absent' => 'Absent',
                        'late' => 'Late',
                        'excused' => 'Excused',
                    ])
                    ->default('present')
                    ->required()
                    ->columnSpan(1),

                TextInput::make('remarks')
                    ->maxLength(255)
                    ->columnSpanFull(),
            ]),
        ]);
    }

    /**
     * @return array<int, string>
     */
    protected static function traineeOptions(int|string|null $batchId): array
    {
        if (blank($batchId)) {
            return [];
        }

        $batch = TrainingBatch::query()
            ->where('tenant_id', app(PortalContext::class)->tenantId())
            ->find($batchId);

        return $batch?->trainees()
            ->orderBy('name')
            ->pluck('name', 'users.id')
            ->all() ?? [];
    }
}
