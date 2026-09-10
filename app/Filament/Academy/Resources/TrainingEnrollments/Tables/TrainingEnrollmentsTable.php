<?php

namespace App\Filament\Academy\Resources\TrainingEnrollments\Tables;

use App\Models\Training\TrainingCourse;
use App\Models\Training\TrainingEnrollment;
use App\Models\Training\TrainingQuizAttempt;
use App\Models\Training\TrainingRemark;
use App\Services\Training\CertificateService;
use App\Support\Portal\PortalContext;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

class TrainingEnrollmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['trainee', 'course', 'batch']))
            ->columns([
                TextColumn::make('trainee.name')
                    ->label('Trainee')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                TextColumn::make('course.title')
                    ->label('Course')
                    ->searchable()
                    ->limit(32),

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

                TextColumn::make('average_score')
                    ->label('Avg. Score')
                    ->state(fn (TrainingEnrollment $record): string => static::averageScore($record).'%')
                    ->alignEnd(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'completed' => 'success',
                        'in_progress' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => str($state)->headline()->toString())
                    ->sortable(),

                TextColumn::make('certificate.certificate_number')
                    ->label('Certificate')
                    ->placeholder('Not issued')
                    ->fontFamily('mono')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'assigned' => 'Assigned',
                        'in_progress' => 'In Progress',
                        'completed' => 'Completed',
                    ]),

                SelectFilter::make('training_course_id')
                    ->label('Course')
                    ->options(fn (): array => TrainingCourse::query()
                        ->where('tenant_id', app(PortalContext::class)->tenantId())
                        ->orderBy('title')
                        ->pluck('title', 'id')
                        ->all()),
            ])
            ->recordActions([
                ActionGroup::make([
                    static::addRemarkAction(),
                    static::issueCertificateAction(),
                ]),
            ])
            ->defaultSort('progress_percentage', 'asc');
    }

    /**
     * Trainer feedback. is_visible_to_trainee defaults to on, but the
     * toggle is what lets a trainer keep an internal note internal.
     */
    protected static function addRemarkAction(): Action
    {
        return Action::make('addRemark')
            ->label('Add remark')
            ->icon('heroicon-o-chat-bubble-left-ellipsis')
            ->schema([
                Textarea::make('remark')
                    ->required()
                    ->rows(4)
                    ->maxLength(2000),

                Toggle::make('is_visible_to_trainee')
                    ->label('Share with the trainee')
                    ->default(true),
            ])
            ->action(function (array $data, TrainingEnrollment $record): void {
                TrainingRemark::create([
                    'training_enrollment_id' => $record->getKey(),
                    'trainer_id' => auth()->id(),
                    'remark' => $data['remark'],
                    'is_visible_to_trainee' => $data['is_visible_to_trainee'],
                ]);

                Notification::make()
                    ->title('Remark saved')
                    ->success()
                    ->send();
            });
    }

    protected static function issueCertificateAction(): Action
    {
        return Action::make('issueCertificate')
            ->label('Issue certificate')
            ->icon('heroicon-o-trophy')
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (TrainingEnrollment $record): bool => $record->certificate()->doesntExist())
            ->action(function (TrainingEnrollment $record): void {
                try {
                    $certificate = app(CertificateService::class)->issue($record, auth()->user());
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title('Cannot issue yet')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Certificate issued')
                    ->body($certificate->certificate_number)
                    ->success()
                    ->send();
            });
    }

    protected static function averageScore(TrainingEnrollment $enrollment): int
    {
        return (int) round(
            TrainingQuizAttempt::query()
                ->where('training_enrollment_id', $enrollment->getKey())
                ->where('status', 'completed')
                ->avg('percentage') ?? 0
        );
    }
}
