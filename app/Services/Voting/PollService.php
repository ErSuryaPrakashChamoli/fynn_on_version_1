<?php

namespace App\Services\Voting;

use App\Enums\NotificationCategory;
use App\Filament\Pages\MyVotes;
use App\Models\Employee;
use App\Models\Poll;
use App\Models\PollRecipient;
use App\Models\PollVote;
use App\Models\User;
use App\Support\Demo\DemoContext;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Publishes polls to their audience, records votes and reads results.
 *
 * Who may raise: the Admin, and any supervisor above caller level (Team
 * Leader upward). Who may see results and voters: the Admin and the poll's
 * creator — and for an anonymous poll nobody sees who chose what, only who
 * has and has not voted.
 */
class PollService
{
    /** Anyone from Team Leader upward, plus the Admin, may raise a poll. */
    public function canRaise(User $user): bool
    {
        if ($user->hasRole('Admin')) {
            return true;
        }

        $designation = $user->employee?->designation;

        return $designation !== null
            && Employee::designationRank((int) $designation) >= Employee::designationRank(Employee::DESIGNATION_TEAM_LEADER);
    }

    /** The Admin sees every poll; a raiser sees their own. */
    public function canManage(Poll $poll, User $user): bool
    {
        return $user->hasRole('Admin') || (int) $poll->created_by === (int) $user->getKey();
    }

    /**
     * Snapshots the audience into poll_recipients and drops a bell
     * notification for each of them. Returns how many were reached. The
     * creator is a recipient like anyone else in the audience: their vote
     * counts too.
     */
    public function publish(Poll $poll): int
    {
        $sent = 0;
        $notification = $this->notificationFor($poll);

        // notifyNow(), not Filament's sendToDatabase(): that one queues every
        // notification, so nothing reaches a bell until a queue worker runs.
        $this->recipientsQuery($poll)->chunkById(500, function ($users) use ($poll, $notification, &$sent): void {
            PollRecipient::query()->insertOrIgnore($users->map(fn (User $user): array => [
                'poll_id' => $poll->getKey(),
                'user_id' => $user->getKey(),
                'voted_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ])->all());

            foreach ($users as $user) {
                $user->notifyNow($notification->toDatabase());
            }

            $sent += $users->count();
        });

        $poll->forceFill(['recipients_count' => $sent])->save();

        return $sent;
    }

    /**
     * Records $user's answer. Refused when the poll is closed or expired,
     * when the user is not in its audience, when they have already voted,
     * when the option is not one of the poll's, or when the poll asks for a
     * reason for that answer and none (or an unlisted one) is given.
     */
    public function vote(Poll $poll, User $user, string $option, ?string $comment = null, ?string $reason = null): PollVote
    {
        if (! $poll->isLive()) {
            throw new AuthorizationException('This poll is closed.');
        }

        $recipient = PollRecipient::query()
            ->where('poll_id', $poll->getKey())
            ->where('user_id', $user->getKey())
            ->first();

        if (! $recipient) {
            throw new AuthorizationException('This poll was not sent to you.');
        }

        if ($recipient->hasVoted()) {
            throw new AuthorizationException('You have already voted on this poll.');
        }

        if (! in_array($option, $poll->optionList(), true)) {
            throw ValidationException::withMessages(['option' => 'Pick one of the poll\'s options.']);
        }

        $comment = $poll->allow_comment ? (trim((string) $comment) ?: null) : null;
        $reason = trim((string) $reason) ?: null;
        $reasonChoices = $poll->reasonsFor($option);

        if ($reasonChoices === []) {
            $reason = null;
        } elseif ($reason === null) {
            throw ValidationException::withMessages(['reason' => 'Pick a reason for your answer.']);
        } elseif (! in_array($reason, $reasonChoices, true)) {
            throw ValidationException::withMessages(['reason' => 'Pick one of the listed reasons.']);
        }

        return DB::transaction(function () use ($poll, $user, $recipient, $option, $comment, $reason): PollVote {
            $vote = PollVote::query()->create([
                'poll_id' => $poll->getKey(),
                // Anonymous: the answer is stored with no trace of who gave it.
                'user_id' => $poll->is_anonymous ? null : $user->getKey(),
                'option' => $option,
                'reason' => $reason,
                'comment' => $comment,
            ]);

            $recipient->forceFill(['voted_at' => now()])->save();
            $poll->increment('votes_count');

            $user->unreadNotifications()
                ->where('category', NotificationCategory::Voting->value)
                ->where('data->viewData->poll_id', $poll->getKey())
                ->update(['read_at' => now()]);

            return $vote;
        });
    }

    /**
     * The polls still waiting on $user (open, not yet voted), mandatory
     * ones first, oldest first.
     *
     * @return Builder<PollRecipient>
     */
    public function pendingFor(User $user): Builder
    {
        return PollRecipient::query()
            ->where('user_id', $user->getKey())
            ->pending()
            ->with('poll.creator');
    }

    /**
     * Mandatory polls still waiting on $user — the ones PollPrompt blocks on.
     *
     * @return Builder<PollRecipient>
     */
    public function blockingFor(User $user): Builder
    {
        return PollRecipient::query()
            ->where('user_id', $user->getKey())
            ->blocking()
            ->with('poll.creator');
    }

    /**
     * Votes per option, in the poll's option order, with percentages of
     * the votes cast.
     *
     * @return Collection<int, array{option: string, votes: int, percent: float}>
     */
    public function results(Poll $poll): Collection
    {
        $counts = PollVote::query()
            ->where('poll_id', $poll->getKey())
            ->selectRaw('`option`, COUNT(*) as aggregate')
            ->groupBy('option')
            ->pluck('aggregate', 'option');

        $total = (int) $counts->sum();

        return collect($poll->optionList())->map(fn (string $option): array => [
            'option' => $option,
            'votes' => (int) ($counts[$option] ?? 0),
            'percent' => $total > 0 ? round(($counts[$option] ?? 0) * 100 / $total, 1) : 0.0,
        ]);
    }

    /**
     * Reasons given per answer, most common first — only for polls that ask.
     *
     * @return Collection<string, Collection<string, int>> option => (reason => count)
     */
    public function reasonBreakdown(Poll $poll): Collection
    {
        if (! $poll->ask_reason) {
            return collect();
        }

        return PollVote::query()
            ->where('poll_id', $poll->getKey())
            ->whereNotNull('reason')
            ->selectRaw('`option`, `reason`, COUNT(*) as aggregate')
            ->groupBy('option', 'reason')
            ->orderByDesc('aggregate')
            ->get()
            ->groupBy('option')
            ->map(fn (Collection $rows): Collection => $rows->mapWithKeys(fn ($row): array => [(string) $row->reason => (int) $row->aggregate]));
    }

    /**
     * Admin-panel users whose login still works (switched on, not exited,
     * not an external portal account), narrowed to the poll's audience.
     *
     * @return Builder<User>
     */
    public function recipientsQuery(Poll $poll): Builder
    {
        return User::query()
            ->where(fn (Builder $query) => $query->where('is_active', true)->orWhereNull('is_active'))
            ->whereDoesntHave('portalAccount')
            ->whereDoesntHave('employee', fn (Builder $query) => $query->where('exit_status', 'yes'))
            ->when(
                $poll->audience === Poll::AUDIENCE_ROLES,
                fn (Builder $query) => $query->whereHas('roles', fn (Builder $query) => $query->whereIn('name', $poll->audience_roles ?? [])),
            )
            ->when(
                $poll->audience === Poll::AUDIENCE_DESIGNATIONS,
                fn (Builder $query) => $query->whereHas('employee', fn (Builder $query) => $query->whereIn('designation', $poll->audience_designations ?? [])),
            );
    }

    private function notificationFor(Poll $poll): Notification
    {
        $deadline = $poll->expires_at ? ' Closes '.$poll->expires_at->format('d M Y, h:i A').'.' : '';

        return Notification::make()
            ->title(($poll->is_mandatory ? 'Your vote is required: ' : 'Vote / feedback: ').$poll->title)
            ->body(str($poll->question)->limit(160).$deadline)
            ->icon(NotificationCategory::Voting->icon())
            ->iconColor($poll->is_mandatory ? 'warning' : 'info')
            ->viewData([
                ...NotificationCategory::Voting->viewData(),
                'poll_id' => $poll->getKey(),
            ])
            ->actions($this->openVotesAction());
    }

    /**
     * @return array<int, Action>
     */
    private function openVotesAction(): array
    {
        $panel = Filament::getCurrentPanel()?->getId() ?? (DemoContext::isActive() ? 'demo' : 'admin');

        try {
            $url = MyVotes::getUrl(panel: $panel);
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }

        return [
            Action::make('openVotes')
                ->label('Vote now')
                ->url($url)
                ->markAsRead(),
        ];
    }
}
