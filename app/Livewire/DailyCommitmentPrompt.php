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
 * The compulsory daily prompt, raised on whatever page the employee is on
 * once a deadline has passed with nothing given.
 *
 * Two faces, decided by DailyCommitmentGate::status():
 *
 *  - "commit" — it is past 09:50 and today has no commitment. A stage and
 *    a number, given here.
 *  - "declare" — it is past 18:30 (or an earlier day was never closed).
 *    The day is answered here too: either the cases that make it up, or a
 *    zero against every stage.
 *
 * Both are answered IN PLACE and the prompt then goes away, leaving the
 * employee on the page they were working on. This prompt is the whole of
 * the enforcement — nothing else in the LMS is closed or redirected,
 * because a commitment module has no business stopping other work.
 *
 * It hides itself on My Commitment, which is the fuller version of the
 * same two steps and would otherwise be covered by it.
 */
class DailyCommitmentPrompt extends Component
{
    /*
    |--------------------------------------------------------------------------
    | Morning
    |--------------------------------------------------------------------------
    */

    public ?string $stage = null;

    public ?string $amount = null;

    public ?string $count = null;

    /*
    |--------------------------------------------------------------------------
    | Evening
    |--------------------------------------------------------------------------
    */

    /** null until the employee says whether there is anything to declare. */
    public ?string $mode = null;

    /** @var array<int, array{customer_name: ?string, mobile_no: ?string, stage: ?string, amount: ?string}> */
    public array $cases = [];

    /** @var array<string, string|null> */
    public array $nilStages = [];

    public ?string $note = null;

    public function mount(): void
    {
        $this->stage ??= CommitmentStage::default()->value;

        $this->nilStages = collect(CommitmentStage::ladder())
            ->mapWithKeys(fn (CommitmentStage $stage): array => [$stage->value => '0'])
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Morning — the promise
    |--------------------------------------------------------------------------
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

        // Re-authorised here as well: the prompt only ever creates the day
        // it is actually asking about.
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

        $existing = DailyCommitment::query()
            ->where('employee_id', $employee->id)
            ->forDate($status['date'])
            ->first();

        // A commitment is a promise: if one somehow already exists for the
        // day, the prompt never rewrites it.
        if (! $existing) {
            $commitment = DailyCommitment::create([
                'employee_id' => $employee->id,
                'date' => $status['date'],
                'commitment_stage' => $stage,
                'commitment_amount' => $amount,
                'commitment_count' => $count,
                'result' => CommitmentResult::InProgress,
                'created_by' => $user->getKey(),
            ]);

            app(DailyCommitmentService::class)->syncCommitment($commitment);

            Notification::make()
                ->title('Commitment given')
                ->body('Declare what you achieved against it before '.DailyCommitmentGate::EVENING_DEADLINE.'.')
                ->success()
                ->send();
        }

        $this->clear();
    }

    /*
    |--------------------------------------------------------------------------
    | Evening — the answer
    |--------------------------------------------------------------------------
    */

    public function chooseMode(?string $mode): void
    {
        $this->mode = in_array($mode, ['cases', 'failed'], true) ? $mode : null;

        if ($this->mode === 'cases' && $this->cases === []) {
            $this->addCase();
        }
    }

    public function addCase(): void
    {
        $this->cases[] = [
            'customer_name' => null,
            'mobile_no' => null,
            'stage' => null,
            'amount' => null,
        ];
    }

    public function removeCase(int $index): void
    {
        unset($this->cases[$index]);

        $this->cases = array_values($this->cases);
    }

    /**
     * Declare the cases that make up the day.
     */
    public function submitCases(): void
    {
        $commitment = $this->blockingCommitment();

        if (! $commitment) {
            return;
        }

        $rows = collect($this->cases)
            ->filter(fn (array $case): bool => filled($case['customer_name'] ?? null))
            ->values()
            ->all();

        if ($rows === []) {
            $this->warn('Name the cases first', 'Add the customers that make up today\'s business, or say nothing came through.');

            return;
        }

        foreach ($rows as $row) {
            if (blank($row['stage'] ?? null) || ! CommitmentStage::tryFrom((string) $row['stage'])) {
                $this->warn('Stage missing', 'Choose the stage each case reached.');

                return;
            }
        }

        $service = app(DailyCommitmentService::class);

        // Validated before anything is written — replaceFulfilment rebuilds
        // by delete-then-insert, so a rejection halfway would lose the lot.
        $reason = $service->reasonMobilesCannotBeSaved($rows, $commitment->id);

        if ($reason !== null) {
            $this->warn('Check the mobile numbers', $reason);

            return;
        }

        $service->replaceFulfilment($commitment, $rows, submit: true);

        Notification::make()->title('Day closed')->success()->send();

        $this->clear();
    }

    /**
     * Close the day with nothing on it. The zeros are the declaration.
     */
    public function declareFailed(): void
    {
        $commitment = $this->blockingCommitment();

        if (! $commitment) {
            return;
        }

        foreach (CommitmentStage::ladder() as $rung) {
            if ((float) ($this->nilStages[$rung->value] ?? 0) > 0) {
                $this->warn(
                    'That is not a failed day',
                    "You have entered business against {$rung->label()}. Declare it as a case instead, with the customer and mobile number.",
                );

                return;
            }
        }

        $commitment->entries()->delete();

        $commitment->forceFill([
            'submitted_at' => now(),
            'declaration_note' => filled($this->note) ? trim($this->note) : null,
        ])->save();

        app(DailyCommitmentService::class)->syncCommitment($commitment->refresh());

        Notification::make()
            ->title('Commitment recorded as failed')
            ->body('Nothing at any stage. Carry on where you left off.')
            ->success()
            ->send();

        $this->clear();
    }

    public function goToDeclaration(): void
    {
        $this->redirect(MyDailyCommitment::getUrl(), navigate: false);
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * The commitment the prompt is currently holding the employee on —
     * re-resolved from the gate on every write, never trusted from the
     * browser.
     */
    private function blockingCommitment(): ?DailyCommitment
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return null;
        }

        $status = app(DailyCommitmentGate::class)->status($user);

        return $status['reason'] === DailyCommitmentGate::REASON_DECLARE
            ? $status['commitment']
            : null;
    }

    private function warn(string $title, string $body): void
    {
        Notification::make()->title($title)->body($body)->warning()->send();
    }

    /**
     * Drop the gate's memo so this component's own re-render finds the
     * question answered and the prompt simply disappears.
     *
     * Deliberately NOT a redirect: the whole point is that the employee is
     * left exactly where they were, on whatever page they were working on,
     * without so much as a page reload.
     */
    private function clear(): void
    {
        app(DailyCommitmentGate::class)->forget();

        $this->reset(['mode', 'cases', 'note', 'amount', 'count']);
    }

    public function render(): View
    {
        $user = Filament::auth()->user();

        $blank = ['blocked' => false, 'reason' => null, 'date' => null, 'commitment' => null, 'overdue' => false];

        $gate = app(DailyCommitmentGate::class);

        $status = $user instanceof User ? $gate->status($user) : $blank;

        // My Commitment is the fuller version of this same form.
        if ($this->onCommitmentPage()) {
            $status = $blank;
        }

        return view('livewire.daily-commitment-prompt', [
            'status' => $status,
            'message' => $status['blocked'] ? $gate->message($status) : '',
            'stageOptions' => CommitmentStage::commitableOptions(),
            'ladderOptions' => CommitmentStage::ladderOptions(),
        ]);
    }

    private function onCommitmentPage(): bool
    {
        return str_contains(request()->route()?->getName() ?? '', '.pages.my-daily-commitment');
    }
}
