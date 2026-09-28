<?php

namespace App\Livewire;

use App\Models\User;
use App\Services\Voting\PollService;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Blocks the LMS behind each live MANDATORY poll the user has not voted on
 * yet (Setting → Voting), oldest first, one at a time. Like the
 * announcement prompt it cannot be closed or clicked past — voting is the
 * only way on. Optional polls never block; they wait on the My Votes page
 * and in the bell.
 */
class PollPrompt extends Component
{
    public ?string $option = null;

    public ?string $comment = null;

    public ?string $reason = null;

    /** A new answer resets the reason: the dropdown depends on it. */
    public function updatedOption(): void
    {
        $this->reason = null;
    }

    public function submitVote(int $recipientId): void
    {
        $user = Filament::auth()->user();
        $recipient = $user instanceof User
            ? app(PollService::class)->blockingFor($user)->whereKey($recipientId)->first()
            : null;

        if (! $recipient) {
            return;
        }

        if (blank($this->option)) {
            $this->addError('option', 'Pick an option to vote.');

            return;
        }

        try {
            app(PollService::class)->vote($recipient->poll, $user, (string) $this->option, $this->comment, $this->reason);
        } catch (ValidationException $exception) {
            $field = array_key_exists('reason', $exception->errors()) ? 'reason' : 'option';
            $this->addError($field, $exception->getMessage());

            return;
        } catch (AuthorizationException $exception) {
            $this->addError('option', $exception->getMessage());

            return;
        }

        $this->reset('option', 'comment', 'reason');
        $this->resetErrorBag();

        // Keeps the bell's unread badge in step without waiting for its poll.
        $this->dispatch('databaseNotificationsSent');
    }

    public function render(): View
    {
        $user = Filament::auth()->user();
        $query = $user instanceof User ? app(PollService::class)->blockingFor($user) : null;

        return view('livewire.poll-prompt', [
            'current' => $query ? (clone $query)->oldest('id')->first() : null,
            'pendingCount' => $query ? (clone $query)->count() : 0,
        ]);
    }
}
