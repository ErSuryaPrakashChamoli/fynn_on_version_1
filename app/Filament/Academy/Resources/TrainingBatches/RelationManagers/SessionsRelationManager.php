<?php

namespace App\Filament\Academy\Resources\TrainingBatches\RelationManagers;

use App\Models\Training\TrainingBatch;
use App\Models\Training\TrainingModule;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SessionsRelationManager extends RelationManager
{
    protected static string $relationship = 'sessions';

    protected static ?string $title = 'Sessions';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),

            Textarea::make('agenda')
                ->rows(2)
                ->columnSpanFull(),

            Select::make('training_module_id')
                ->label('Module')
                ->options(fn (): array => $this->moduleOptions())
                ->preload()
                ->columnSpanFull(),

            DateTimePicker::make('scheduled_at')
                ->native(false)
                ->seconds(false)
                ->required(),

            TextInput::make('duration_minutes')
                ->label('Duration (minutes)')
                ->numeric()
                ->minValue(15)
                ->default(60)
                ->required(),

            Select::make('mode')
                ->options([
                    'classroom' => 'Classroom',
                    'online' => 'Online',
                    'field' => 'On the floor',
                ])
                ->default('classroom')
                ->required(),

            TextInput::make('location')
                ->maxLength(255),

            Select::make('status')
                ->options([
                    'scheduled' => 'Scheduled',
                    'completed' => 'Completed',
                    'cancelled' => 'Cancelled',
                ])
                ->default('scheduled')
                ->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->defaultSort('scheduled_at')
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->weight('medium'),

                TextColumn::make('scheduled_at')
                    ->label('When')
                    ->dateTime('d M Y, H:i')
                    ->sortable(),

                TextColumn::make('duration_minutes')
                    ->label('Duration')
                    ->formatStateUsing(fn (int $state): string => $state.' min')
                    ->alignEnd(),

                TextColumn::make('mode')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => str($state)->headline()->toString()),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => str($state)->headline()->toString()),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    /**
     * Modules are offered only from courses in this batch's own tenant.
     *
     * @return array<int, string>
     */
    protected function moduleOptions(): array
    {
        /** @var TrainingBatch $batch */
        $batch = $this->getOwnerRecord();

        return TrainingModule::query()
            ->whereHas('course', fn ($query) => $query->where('tenant_id', $batch->tenant_id))
            ->with('course')
            ->get()
            ->mapWithKeys(fn (TrainingModule $module): array => [
                $module->getKey() => $module->course?->title.' — '.$module->title,
            ])
            ->all();
    }
}
