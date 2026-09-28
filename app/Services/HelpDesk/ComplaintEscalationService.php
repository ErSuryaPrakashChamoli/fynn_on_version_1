<?php

namespace App\Services\HelpDesk;

use App\Models\Complaint;
use App\Models\User;
use App\Support\ReportingTree;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Pushes unresolved tickets up the reporting line when their deadline
 * passes.
 *
 * Level 1 fires once due_at has passed: the ticket goes to the supervisor
 * of whoever holds it — the assignee's nearest active boss, or, for a team
 * ticket nobody has taken up (or a handler with no employee record), the
 * Admins. Level 2 fires after a second full SLA window with the ticket
 * still unresolved: the boss above that, or the Admins. Each level fires
 * once (complaints.escalation_level); reopening resets to 0.
 */
class ComplaintEscalationService
{
    public const MAX_LEVEL = 2;

    public function __construct(private ComplaintService $complaints) {}

    /**
     * Escalates every unresolved ticket that has crossed a level since the
     * last run. Returns how many tickets were escalated.
     */
    public function escalateOverdue(?Carbon $now = null): int
    {
        $now ??= now();

        $candidates = Complaint::query()
            ->overdue($now)
            ->where('escalation_level', '<', self::MAX_LEVEL)
            ->with(['assignee.employee', 'escalatee.employee', 'raiser', 'priority', 'category'])
            ->get();

        if ($candidates->isEmpty()) {
            return 0;
        }

        $tree = ReportingTree::load();
        $admins = User::query()->role('Admin')->where('is_active', true)->get();
        $escalated = 0;

        foreach ($candidates as $complaint) {
            $targetLevel = $this->levelDueAt($complaint, $now);

            if ($targetLevel <= $complaint->escalation_level) {
                continue;
            }

            $recipients = $this->recipientsForLevel($complaint, $targetLevel, $tree, $admins);

            $complaint->update([
                'escalation_level' => $targetLevel,
                'escalated_at' => $now,
                'escalated_to' => $recipients->count() === 1 ? $recipients->first()->getKey() : ($complaint->escalated_to ?? $recipients->first()?->getKey()),
            ]);

            $names = $recipients->pluck('name')->implode(', ') ?: 'nobody (no supervisor or Admin found)';
            $overdueBy = $complaint->due_at->diffForHumans($now, ['syntax' => Carbon::DIFF_ABSOLUTE, 'parts' => 2]);

            $this->complaints->logEvent($complaint, null, sprintf(
                'Escalated (level %d) to %s — unresolved %s past the %s deadline.',
                $targetLevel,
                $names,
                $overdueBy,
                $complaint->priority->name,
            ));

            $this->complaints->notify(
                $recipients,
                sprintf('Escalation: ticket %s overdue by %s', $complaint->ticket_no, $overdueBy),
                sprintf(
                    '"%s" (%s, %s priority) raised by %s is with %s and was due %s.',
                    $complaint->subject,
                    $complaint->category->name,
                    $complaint->priority->name,
                    $complaint->raiser->name,
                    $complaint->handlerLabel(),
                    ComplaintService::describeDeadline($complaint->due_at),
                ),
                $complaint,
                $targetLevel === 1 ? 'warning' : 'danger',
            );

            if ($complaint->assignee && ! $recipients->contains('id', $complaint->assignee->getKey())) {
                $this->complaints->notify(
                    [$complaint->assignee],
                    'Ticket '.$complaint->ticket_no.' escalated to '.$names,
                    'It is overdue by '.$overdueBy.'. Please resolve it or update the thread.',
                    $complaint,
                    'danger',
                );
            }

            $escalated++;
        }

        return $escalated;
    }

    /**
     * Which level the ticket should be at now: 1 once due_at has passed,
     * 2 once a second SLA window has passed as well.
     */
    public function levelDueAt(Complaint $complaint, Carbon $now): int
    {
        if ($complaint->due_at === null || $complaint->due_at->gte($now)) {
            return 0;
        }

        $secondWindowEndsAt = $complaint->due_at->copy()->addMinutes((int) $complaint->priority->resolve_within_minutes);

        return $secondWindowEndsAt->lt($now) ? 2 : 1;
    }

    /**
     * The supervisors a level goes to: the Nth active boss above whoever
     * holds the ticket, falling back to the Admins when the line runs out
     * (or the ticket has no individual holder).
     *
     * @param  Collection<int, User>  $admins
     * @return Collection<int, User>
     */
    public function recipientsForLevel(Complaint $complaint, int $level, ReportingTree $tree, Collection $admins): Collection
    {
        $holderEmployeeId = $complaint->assignee?->employee_id;

        if ($holderEmployeeId !== null) {
            $bosses = array_values(array_filter(
                $tree->ancestorIds((int) $holderEmployeeId),
                fn (int $id): bool => ! $tree->isExited($id),
            ));

            $bossId = $bosses[$level - 1] ?? null;

            if ($bossId !== null) {
                $users = User::query()->where('employee_id', $bossId)->where('is_active', true)->get();

                if ($users->isNotEmpty()) {
                    return $users;
                }
            }
        }

        return $admins->values();
    }
}
