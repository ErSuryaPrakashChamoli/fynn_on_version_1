<?php

namespace App\Filament\Academy\Widgets;

use App\Models\Training\TrainingSession;
use App\Support\Portal\PortalContext;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class UpcomingSessionsTable extends TableWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 1;

    public static function canView(): bool
    {
        return ! app(PortalContext::class)->isTrainee();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Upcoming Sessions')
            ->query($this->baseQuery())
            ->defaultSort('scheduled_at', 'asc')
            ->paginated([5, 10])
            ->columns([
                TextColumn::make('title')
                    ->label('Session')
                    ->limit(36)
                    ->searchable(),

                TextColumn::make('batch.name')
                    ->label('Batch')
                    ->limit(24),

                TextColumn::make('scheduled_at')
                    ->label('When')
                    ->dateTime('d M Y, H:i')
                    ->sortable(),

                TextColumn::make('mode')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => str($state)->headline()->toString()),
            ]);
    }

    protected function baseQuery(): Builder
    {
        return TrainingSession::query()
            ->with('batch')
            ->whereHas(
                'batch',
                fn (Builder $query) => $query->where('tenant_id', app(PortalContext::class)->tenantId())
            )
            ->where('scheduled_at', '>=', now()->startOfDay())
            ->where('status', 'scheduled');
    }
}
