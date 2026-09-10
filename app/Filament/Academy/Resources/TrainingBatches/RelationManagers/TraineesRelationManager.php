<?php

namespace App\Filament\Academy\Resources\TrainingBatches\RelationManagers;

use App\Enums\PortalRole;
use App\Models\Training\TrainingBatch;
use App\Models\Training\TrainingCourse;
use App\Models\Training\TrainingEnrollment;
use App\Models\User;
use App\Support\Portal\PortalAudit;
use App\Support\Portal\PortalContext;
use Filament\Actions\Action;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Batch membership, plus the action that turns membership into actual
 * course access.
 *
 * Attaching a trainee to a batch does NOT by itself grant them anything
 * — the enrollment row is what does, which is why "Assign course" is a
 * separate, explicit action rather than a side effect.
 */
class TraineesRelationManager extends RelationManager
{
    protected static string $relationship = 'trainees';

    protected static ?string $title = 'Trainees';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->weight('medium'),

                TextColumn::make('email')
                    ->searchable()
                    ->copyable(),

                TextColumn::make('enrollment_progress')
                    ->label('Progress')
                    ->state(fn (User $record): string => $this->progressFor($record).'%')
                    ->badge()
                    ->color(fn (User $record): string => match (true) {
                        $this->progressFor($record) >= 80 => 'success',
                        $this->progressFor($record) >= 50 => 'warning',
                        default => 'danger',
                    })
                    ->alignEnd(),

                TextColumn::make('pivot.joined_at')
                    ->label('Joined')
                    ->dateTime('d M Y')
                    ->placeholder('—'),
            ])
            ->headerActions([
                AttachAction::make()
                    ->recordSelectSearchColumns(['name', 'email'])
                    /*
                     * The attachable list is restricted to trainee
                     * portal accounts in this tenant. Without this,
                     * Filament's default would offer EVERY user row in
                     * the application — including production LMS staff.
                     */
                    ->recordSelectOptionsQuery(fn ($query) => $query
                        ->whereHas('portalAccount', fn ($accountQuery) => $accountQuery
                            ->where('tenant_id', app(PortalContext::class)->tenantId())
                            ->where('portal_role', PortalRole::Trainee->value)
                            ->where('is_active', true)))
                    ->after(fn ($record) => $record->trainees()->updateExistingPivot(
                        $record->getKey(),
                        ['joined_at' => now()],
                    )),
            ])
            ->recordActions([
                Action::make('assignCourse')
                    ->label('Assign course')
                    ->icon('heroicon-o-academic-cap')
                    ->schema([
                        Select::make('training_course_id')
                            ->label('Course')
                            ->options(fn (): array => TrainingCourse::query()
                                ->where('tenant_id', app(PortalContext::class)->tenantId())
                                ->where('status', 'published')
                                ->orderBy('title')
                                ->pluck('title', 'id')
                                ->all())
                            ->required()
                            ->preload(),
                    ])
                    ->action(function (array $data, User $record): void {
                        /** @var TrainingBatch $batch */
                        $batch = $this->getOwnerRecord();

                        $enrollment = TrainingEnrollment::updateOrCreate(
                            [
                                'training_course_id' => $data['training_course_id'],
                                'trainee_id' => $record->getKey(),
                            ],
                            [
                                'tenant_id' => $batch->tenant_id,
                                'training_batch_id' => $batch->getKey(),
                                'status' => 'assigned',
                                'enrolled_at' => now(),
                            ]
                        );

                        PortalAudit::traineeAssigned($enrollment);

                        Notification::make()
                            ->title('Course assigned')
                            ->body("{$record->name} can now start the course.")
                            ->success()
                            ->send();
                    }),

                DetachAction::make(),
            ]);
    }

    protected function progressFor(User $trainee): int
    {
        /** @var TrainingBatch $batch */
        $batch = $this->getOwnerRecord();

        return (int) round(
            $batch->enrollments()
                ->where('trainee_id', $trainee->getKey())
                ->avg('progress_percentage') ?? 0
        );
    }
}
