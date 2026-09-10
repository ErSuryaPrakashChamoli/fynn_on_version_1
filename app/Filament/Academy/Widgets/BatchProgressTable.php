<?php

namespace App\Filament\Academy\Widgets;

use App\Models\Training\TrainingEnrollment;
use App\Support\Portal\PortalContext;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Trainee-by-trainee progress for the trainer's tenant.
 */
class BatchProgressTable extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return ! app(PortalContext::class)->isTrainee();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Trainee Progress')
            ->description('Every active enrollment, weakest first.')
            ->query($this->baseQuery())
            ->defaultSort('progress_percentage', 'asc')
            ->paginated([5, 10, 25])
            ->columns([
                TextColumn::make('trainee.name')
                    ->label('Trainee')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('course.title')
                    ->label('Course')
                    ->limit(40)
                    ->toggleable(),

                TextColumn::make('batch.name')
                    ->label('Batch')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('progress_percentage')
                    ->label('Progress')
                    ->badge()
                    ->formatStateUsing(fn (int $state): string => $state.'%')
                    ->color(fn (int $state): string => match (true) {
                        $state >= 80 => 'success',
                        $state >= 50 => 'warning',
                        default => 'danger',
                    })
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => str($state)->headline()->toString()),
            ]);
    }

    /**
     * Tenant-scoped at the source so no column, filter or sort can widen
     * it. Trainees never reach this widget (canView above), but the
     * scoping is applied regardless.
     */
    protected function baseQuery(): Builder
    {
        return TrainingEnrollment::query()
            ->with(['trainee', 'course', 'batch'])
            ->where('tenant_id', app(PortalContext::class)->tenantId());
    }
}
