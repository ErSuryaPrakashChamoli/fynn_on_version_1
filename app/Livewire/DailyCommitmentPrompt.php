<?php

namespace App\Livewire;

use App\Enums\CommitmentResult;
use App\Enums\CommitmentStage;
use App\Filament\Pages\MyDailyCommitment;
use App\Models\DailyCommitment;
use App\Models\User;
use App\Services\DailyCommitmentGate;
use App\Services\DailyCommitmentService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The blocking daily prompt, rendered on every panel page once a deadline
 * has passed with nothing given.
 *
 * Two faces, decided by DailyCommitmentGate::status():
 *
 *  - "commit" — it is past 09:50 and today has no commitment. The promise
 *    is a stage and a number, so it is made right here in the prompt and
 *    the panel opens again immediately.
 *  - "declare" — it is past 18:30 (or an earlier day was never closed)
 *    and the commitment is unanswered. Declaring means naming the cases
 *    that make up the day, so this face sends the employee to My
 *    Commitment rather than pretending a modal can hold that.
 *
 * The prompt hides itself on My Commitment: the block is already in force
 * there through the middleware, and covering that page would hide the
 * very form that clears it.
 */
class DailyCommitmentPrompt extends Component
{
    public ?string $stage = null;

    public ?string $amount = null;

    public ?string $count = null;

    public function mount(): void
    {
        $this->stage ??= CommitmentStage::default()->value;
    }

    /**
     * Give today's promise without leaving the page.
     */
    public function commit(): void
    {
        $user = Filament::auth()->user();
        $employee = $user?->employee;

        if (! $employee) {
            return;
        }

        $gate = app(DailyCommitmentGate::class);
        $status = $gate->status($user);

        // Re-authorised here as well as in the middleware: the prompt is
        // only ever allowed to create the day it is actually blocking on.
        if ($status['reason'] !== DailyCommitmentGate::REASON_COMMIT) {
            return;
        }

        $stage = CommitmentStage::tryFrom((string) $this->stage);

        if (! $stage) {
            $this->addError('stage', 'Choose what you are committing to.');

            return;
        }

        $amount = $stage->isCount() ? 0.0 : (float) $this->amount;
        $count = $stage->isCount() ? (int) $this->count : 0;

        if ($stage->isCount() ? $count < 1 : $amount < 1) {
            $this->addError($stage->isCount() ? 'count' : 'amount', 'Enter the number you are committing to.');

            return;
        }

        $date = $status['date'];

        $commitment = DailyCommitment::query()
            ->where('employee_id', $employee->id)
            ->forDate($date)
            ->first();

        // A commitment is a promise: if one somehow already exists for the
        // day, the prompt never rewrites it.
        if ($commitment) {
            $gate->forget();

            $this->redirect(Filament::getUrl(), navigate: false);

            return;
        }

        $commitment = DailyCommitment::create([
            'employee_id' => $employee->id,
            'date' => $date,
            'commitment_stage' => $stage,
            'commitment_amount' => $amount,
            'commitment_count' => $count,
            'result' => CommitmentResult::InProgress,
            'created_by' => $user->getKey(),
        ]);

        app(DailyCommitmentService::class)->syncCommitment($commitment);

        $gate->forget();

        Notification::make()
            ->title('Commitment given')
            ->body('Declare what you achieved against it before '.DailyCommitmentGate::EVENING_DEADLINE.'.')
            ->success()
            ->send();

        $this->redirect(Filament::getUrl(), navigate: false);
    }

    public function goToDeclaration(): void
    {
        $this->redirect(MyDailyCommitment::getUrl(), navigate: false);
    }

    public function render(): View
    {
        $user = Filament::auth()->user();

        $blank = ['blocked' => false, 'reason' => null, 'date' => null, 'commitment' => null, 'overdue' => false];

        $gate = app(DailyCommitmentGate::class);

        $status = $user instanceof User ? $gate->status($user) : $blank;

        // My Commitment carries the block itself — never cover it.
        if ($this->onCommitmentPage()) {
            $status = $blank;
        }

        return view('livewire.daily-commitment-prompt', [
            'status' => $status,
            'message' => $status['blocked'] ? $gate->message($status) : '',
            'stageOptions' => CommitmentStage::commitableOptions(),
        ]);
    }

    private function onCommitmentPage(): bool
    {
        return str_contains(request()->route()?->getName() ?? '', '.pages.my-daily-commitment');
    }
}
