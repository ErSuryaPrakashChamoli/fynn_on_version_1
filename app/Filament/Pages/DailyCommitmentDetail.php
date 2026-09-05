<?php

namespace App\Filament\Pages;

use App\Enums\CommitmentStage;
use App\Models\DailyCommitment;
use App\Models\DailyCommitmentLog;
use App\Services\DailyCommitmentService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * One commitment in full: what was promised, which customers were
 * declared against it, and every change that was logged along the way.
 * Reached by clicking a row on the dashboard or team view.
 *
 * The commitment itself is read-only here for everyone except an Admin —
 * see DailyCommitment::isEditableBy().
 */
class DailyCommitmentDetail extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentMagnifyingGlass;

    protected static ?string $slug = 'daily-commitment-detail';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.daily-commitment.detail';

    public DailyCommitment $commitment;

    /**
     * The slug stays parameterless so the route name remains
     * "…pages.daily-commitment-detail"; only the path takes the record.
     */
    public static function getRoutePath(Panel $panel): string
    {
        return '/'.static::getSlug($panel).'/{record}';
    }

    public function mount(int|string $record): void
    {
        $commitment = DailyCommitment::query()
            ->with(['employee', 'entries.customer'])
            ->findOrFail($record);

        abort_unless(
            app(DailyCommitmentService::class)->canView(Filament::auth()->user(), $commitment->employee_id),
            403,
        );

        $this->commitment = $commitment;
    }

    public function getTitle(): string
    {
        return $this->commitment->employee?->emp_name.' — '.$this->commitment->date->format('d M Y');
    }

    /**
     * @return array<string, mixed>
     */
    public function getRowProperty(): array
    {
        return app(DailyCommitmentService::class)
            ->dailyRows(collect([$this->commitment->employee_id]), $this->commitment->date)
            ->first();
    }

    /**
     * @return Collection<int, DailyCommitmentLog>
     */
    public function getLogsProperty(): Collection
    {
        return $this->commitment->logs()->get();
    }

    public function canEditCommitment(): bool
    {
        return $this->commitment->isEditableBy(Filament::auth()->user());
    }

    /**
     * Admin-only correction of a locked commitment. Writes the same
     * old/new log row every other change writes.
     */
    public function editCommitmentAction(): Action
    {
        return Action::make('editCommitment')
            ->label('Edit commitment')
            ->icon('heroicon-o-pencil-square')
            ->color('warning')
            ->visible(fn (): bool => $this->canEditCommitment())
            ->modalHeading('Correct this commitment')
            ->modalDescription('Commitments are locked once given. This correction is logged against the record.')
            ->schema([
                Select::make('commitment_stage')
                    ->label('Stage')
                    ->options(CommitmentStage::commitableOptions())
                    ->native(false)
                    ->required()
                    ->live(),

                TextInput::make('commitment_amount')
                    ->label('Amount (₹)')
                    ->numeric()
                    ->minValue(1)
                    ->required(fn (Get $get): bool => $get('commitment_stage') !== CommitmentStage::Otp->value)
                    ->visible(fn (Get $get): bool => $get('commitment_stage') !== CommitmentStage::Otp->value),

                TextInput::make('commitment_count')
                    ->label('Number of OTPs')
                    ->numeric()
                    ->minValue(1)
                    ->required(fn (Get $get): bool => $get('commitment_stage') === CommitmentStage::Otp->value)
                    ->visible(fn (Get $get): bool => $get('commitment_stage') === CommitmentStage::Otp->value),

                Textarea::make('note')
                    ->label('Reason for the correction')
                    ->rows(2)
                    ->required()
                    ->maxLength(500),
            ])
            ->fillForm(fn (): array => [
                'commitment_stage' => $this->commitment->commitment_stage->value,
                'commitment_amount' => (int) $this->commitment->commitment_amount ?: null,
                'commitment_count' => $this->commitment->commitment_count ?: null,
            ])
            ->action(function (array $data): void {
                if (! $this->canEditCommitment()) {
                    Notification::make()->title('Only an Admin can change a commitment.')->danger()->send();

                    return;
                }

                $stage = CommitmentStage::from($data['commitment_stage']);
                $amount = $stage->isCount() ? 0 : (float) ($data['commitment_amount'] ?? 0);
                $count = $stage->isCount() ? (int) ($data['commitment_count'] ?? 0) : 0;

                DailyCommitmentLog::create([
                    'daily_commitment_id' => $this->commitment->id,
                    'employee_id' => Filament::auth()->user()?->employee?->id,
                    'old_stage' => $this->commitment->commitment_stage->value,
                    'new_stage' => $stage->value,
                    'old_amount' => $this->commitment->commitment_amount,
                    'new_amount' => $amount,
                    'old_count' => $this->commitment->commitment_count,
                    'new_count' => $count,
                    'change_type' => 'admin_correction',
                    'note' => $data['note'],
                ]);

                $this->commitment->update([
                    'commitment_stage' => $stage,
                    'commitment_amount' => $amount,
                    'commitment_count' => $count,
                ]);

                app(DailyCommitmentService::class)->syncCommitment($this->commitment->refresh());

                Notification::make()->title('Commitment corrected')->success()->send();
            });
    }

    protected function getHeaderActions(): array
    {
        return [$this->editCommitmentAction()];
    }

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return (bool) ($user?->hasRole('Admin') || $user?->employee);
    }
}
