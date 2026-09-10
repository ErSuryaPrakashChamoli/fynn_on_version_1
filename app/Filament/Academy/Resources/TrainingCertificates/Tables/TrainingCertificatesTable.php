<?php

namespace App\Filament\Academy\Resources\TrainingCertificates\Tables;

use App\Models\Training\TrainingCourse;
use App\Support\Portal\PortalContext;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TrainingCertificatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['trainee', 'course', 'issuer']))
            ->columns([
                TextColumn::make('certificate_number')
                    ->label('Number')
                    ->searchable()
                    ->fontFamily('mono')
                    ->copyable(),

                TextColumn::make('trainee.name')
                    ->label('Trainee')
                    ->searchable()
                    ->weight('medium'),

                TextColumn::make('course.title')
                    ->label('Course')
                    ->searchable()
                    ->limit(32),

                TextColumn::make('final_score')
                    ->label('Score')
                    ->badge()
                    ->formatStateUsing(fn (int $state): string => $state.'%')
                    ->color(fn (int $state): string => $state >= 70 ? 'success' : 'warning')
                    ->alignEnd()
                    ->sortable(),

                TextColumn::make('issued_on')
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('issuer.name')
                    ->label('Issued by')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('training_course_id')
                    ->label('Course')
                    ->options(fn (): array => TrainingCourse::query()
                        ->where('tenant_id', app(PortalContext::class)->tenantId())
                        ->orderBy('title')
                        ->pluck('title', 'id')
                        ->all()),
            ])
            ->defaultSort('issued_on', 'desc');
    }
}
