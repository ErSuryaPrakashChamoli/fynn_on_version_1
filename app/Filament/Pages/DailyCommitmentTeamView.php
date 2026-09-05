<?php

namespace App\Filament\Pages;

use App\Models\DailyCallerOtp;
use App\Models\Employee;
use App\Services\DailyCommitmentService;
use App\Support\HierarchyHelper;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * One screen that covers sections 16-19 of the spec by following the
 * existing hierarchy rather than hard-coding four separate views: you
 * always see your own commitment, your direct reportees, and (for a
 * Manager or Cluster Manager) every caller underneath you. Clicking a
 * reportee drills into their own team using the same code.
 */
class DailyCommitmentTeamView extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Daily Commitment';

    protected static ?string $navigationLabel = 'Team View';

    protected static ?string $title = 'Team Commitments';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.daily-commitment.team-view';

    public ?array $data = [];

    /** The employee whose team is currently being shown. */
    public ?int $focusId = null;

    public function mount(): void
    {
        $this->form->fill(['date' => today()->toDateString()]);
        $this->focusId = Filament::auth()->user()?->employee?->id;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                DatePicker::make('date')
                    ->label('Date')
                    ->native(false)
                    ->displayFormat('d M Y')
                    ->default(today())
                    ->live(),
            ])
            ->columns(4)
            ->statePath('data');
    }

    /**
     * Drill into a reportee's team. Guarded server-side: you can only
     * focus someone inside your own visible hierarchy.
     */
    public function focusOn(int $employeeId): void
    {
        if (! app(DailyCommitmentService::class)->canView(Filament::auth()->user(), $employeeId)) {
            Notification::make()->title('You cannot view that team.')->danger()->send();

            return;
        }

        $this->focusId = $employeeId;
    }

    public function resetFocus(): void
    {
        $this->focusId = Filament::auth()->user()?->employee?->id;
    }

    public function getDateProperty(): Carbon
    {
        return Carbon::parse($this->data['date'] ?? today())->startOfDay();
    }

    public function getFocusProperty(): ?Employee
    {
        return $this->focusId ? Employee::find($this->focusId) : null;
    }

    /**
     * The focused person's own commitment row.
     *
     * @return array<string, mixed>|null
     */
    public function getOwnRowProperty(): ?array
    {
        if (! $this->focus) {
            return null;
        }

        return app(DailyCommitmentService::class)
            ->dailyRows(collect([$this->focus->id]), $this->date)
            ->first();
    }

    /**
     * Direct reportees (Cluster Manager -> Managers -> Team Leaders ->
     * Callers), using the app's existing hierarchy walker.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getReporteeRowsProperty(): Collection
    {
        // An Admin account has no employee row of its own, so there is
        // nothing to focus until they drill in — start them at the top of
        // the tree (Cluster Managers) via the same helper the rest of the
        // app uses for "who reports to me".
        $ids = $this->focus
            ? HierarchyHelper::children($this->focus)->pluck('id')
            : HierarchyHelper::directReportees(Filament::auth()->user())->pluck('id');

        return app(DailyCommitmentService::class)->dailyRows($ids, $this->date);
    }

    /**
     * Every caller under the focused person — the OTP/attendance table.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getCallerRowsProperty(): Collection
    {
        $service = app(DailyCommitmentService::class);

        if (! $this->focus) {
            $ids = Employee::query()
                ->whereIn('id', $service->visibleEmployeeIds(Filament::auth()->user()))
                ->where('designation', Employee::DESIGNATION_CALLER)
                ->pluck('id');

            return $service->dailyRows($ids, $this->date);
        }

        if ($this->focus->designation === Employee::DESIGNATION_CALLER) {
            return collect();
        }

        return $service->dailyRows(HierarchyHelper::callerIds($this->focus), $this->date);
    }

    /**
     * @return array<string, mixed>
     */
    public function getCallerSummaryProperty(): array
    {
        return app(DailyCommitmentService::class)->summarise($this->callerRows);
    }

    /**
     * Set a caller's expected OTP for the selected day. Kept as a modal
     * action here rather than a screen of its own — it is one number.
     */
    public function setExpectedOtpAction(): Action
    {
        return Action::make('setExpectedOtp')
            ->label('Set expected OTP')
            ->icon('heroicon-o-pencil-square')
            ->modalHeading('Expected OTP')
            ->modalWidth('sm')
            ->schema([
                TextInput::make('expected_otp')
                    ->label('Expected OTP for the day')
                    ->numeric()
                    ->minValue(0)
                    ->required(),
            ])
            ->fillForm(function (array $arguments): array {
                $existing = DailyCallerOtp::query()
                    ->where('employee_id', $arguments['employee'] ?? 0)
                    ->forDate($this->date)
                    ->first();

                return ['expected_otp' => $existing?->expected_otp ?? 0];
            })
            ->action(function (array $arguments, array $data): void {
                $employeeId = (int) ($arguments['employee'] ?? 0);

                if (! app(DailyCommitmentService::class)->canView(Filament::auth()->user(), $employeeId)) {
                    Notification::make()->title('You cannot set that caller\'s target.')->danger()->send();

                    return;
                }

                DailyCallerOtp::updateOrCreate(
                    ['employee_id' => $employeeId, 'date' => $this->date->toDateString()],
                    ['expected_otp' => (int) $data['expected_otp'], 'set_by' => Filament::auth()->id()],
                );

                Notification::make()->title('Expected OTP saved')->success()->send();
            });
    }

    public function canSetExpectedOtp(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user?->hasAnyRole(['Admin', 'Cluster Manager', 'Manager', 'Team Leader']);
    }

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return false;
        }

        if ($user->hasRole('Admin')) {
            return true;
        }

        $employee = $user->employee;

        return $employee !== null
            && $employee->designation !== Employee::DESIGNATION_CALLER;
    }
}
