<?php

namespace App\Livewire;

use App\Enums\CommitmentStage;
use App\Models\Employee;
use App\Models\MonthlyCommitmentTarget;
use App\Models\User;
use App\Services\MonthlyTargetGate;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * The blocking monthly-target prompt, rendered on every panel page from
 * the 1st of the month until the month's targets exist.
 *
 * Two faces, decided by MonthlyTargetGate::status():
 *
 *  - "set_targets" — the user owns targets that are still unfixed, and
 *    fixes them right here (a Manager for their callers, the Admin line
 *    for Managers and Team Leaders). An "apply to all" row fills a whole
 *    team in one go.
 *  - "awaiting_target" — the user's own target has not been fixed yet,
 *    so they are told exactly who to chase.
 *
 * Every write is re-authorised against the gate, so a crafted Livewire
 * request cannot set a target for somebody outside the user's team.
 */
class MonthlyTargetPrompt extends Component
{
    /**
     * Per-employee input, keyed by employee id.
     *
     * @var array<int, array{stage: ?string, amount: ?string, count: ?string}>
     */
    public array $targets = [];

    public ?string $bulkStage = null;

    public ?string $bulkAmount = null;

    public ?string $bulkCount = null;

    public function mount(): void
    {
        $this->primeRows();
    }

    /**
     * Copies the "apply to all" values onto every row still left blank,
     * so a Manager with thirty callers is not typing the same number
     * thirty times.
     */
    public function applyToAll(): void
    {
        if (blank($this->bulkStage)) {
            Notification::make()
                ->title('Pick a stage first')
                ->warning()
                ->send();

            return;
        }

        foreach ($this->missing() as $employee) {
            $this->targets[$employee->id] = [
                'stage' => $this->bulkStage,
                'amount' => $this->bulkAmount,
                'count' => $this->bulkCount,
            ];
        }
    }

    /**
     * Writes every row that has been filled in. Partly filled is allowed
     * — the prompt simply comes back with whoever is still outstanding.
     */
    public function saveTargets(): void
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return;
        }

        $gate = app(MonthlyTargetGate::class);
        $month = $gate->month();
        $saved = 0;

        foreach ($this->missing() as $employee) {
            $row = $this->targets[$employee->id] ?? [];
            $stage = CommitmentStage::tryFrom((string) ($row['stage'] ?? ''));

            if (! $stage || ! $gate->canSetTargetFor($user, $employee->id)) {
                continue;
            }

            $amount = (float) ($row['amount'] ?? 0);
            $count = (int) ($row['count'] ?? 0);

            // A target of zero is not a target — leave the row outstanding
            // rather than writing a number nobody has to meet.
            if ($stage->isCount() ? $count < 1 : $amount < 1) {
                continue;
            }

            $values = [
                'stage' => $stage,
                'target_amount' => $stage->isCount() ? 0 : $amount,
                'target_count' => $stage->isCount() ? $count : 0,
            ];

            // Looked up with forMonth()/whereDate rather than
            // updateOrCreate(): the `month` cast writes "Y-m-d H:i:s", so a
            // bare "Y-m-d" match would miss the existing row and trip the
            // (employee_id, month) unique index instead of updating it.
            $existing = MonthlyCommitmentTarget::query()
                ->where('employee_id', $employee->id)
                ->forMonth($month)
                ->first();

            $existing
                ? $existing->update($values)
                : MonthlyCommitmentTarget::create([
                    'employee_id' => $employee->id,
                    'month' => $month->toDateString(),
                    ...$values,
                ]);

            $saved++;
        }

        if ($saved === 0) {
            Notification::make()
                ->title('Nothing saved')
                ->body('Every target needs a stage and a number greater than zero.')
                ->warning()
                ->send();

            return;
        }

        $gate->forget();

        Notification::make()
            ->title($saved.' '.str('target')->plural($saved).' fixed')
            ->success()
            ->send();

        // A full reload is deliberate: clearing the block changes what the
        // whole panel is allowed to render, not just this component.
        $this->redirect(Filament::getUrl(), navigate: false);
    }

    public function refreshStatus(): void
    {
        app(MonthlyTargetGate::class)->forget();

        $this->redirect(Filament::getUrl(), navigate: false);
    }

    public function render(): View
    {
        $user = Filament::auth()->user();

        $status = $user instanceof User
            ? app(MonthlyTargetGate::class)->status($user)
            : ['blocked' => false, 'reason' => null, 'month' => today()->startOfMonth(), 'missing' => collect(), 'setter' => null];

        return view('livewire.monthly-target-prompt', [
            'status' => $status,
            'stageOptions' => CommitmentStage::commitableOptions(),
            'designations' => Employee::designationOptions(),
        ]);
    }

    /**
     * @return Collection<int, Employee>
     */
    private function missing(): Collection
    {
        $user = Filament::auth()->user();

        return $user instanceof User
            ? app(MonthlyTargetGate::class)->missingTargets($user)
            : collect();
    }

    private function primeRows(): void
    {
        foreach ($this->missing() as $employee) {
            $this->targets[$employee->id] ??= [
                'stage' => null,
                'amount' => null,
                'count' => null,
            ];
        }
    }
}
