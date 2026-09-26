<?php

namespace App\Services;

use App\Enums\FollowUpOutcome;
use App\Enums\NotificationCategory;
use App\Filament\Pages\FollowUpMonitor;
use App\Models\Employee;
use App\Models\FollowUp;
use App\Models\User;
use App\Support\Demo\DemoContext;
use App\Support\ReportingTree;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Pushes neglected follow-ups up the reporting line, and gives supervisors
 * a morning summary of their team's follow-ups.
 *
 * Escalation: a prospect's current follow-up still open LEVEL_ONE_HOURS
 * after its time goes to the owner's nearest active boss; still open after
 * LEVEL_TWO_DAYS it also goes to the boss above that. follow_ups
 * .escalation_level records how far a row has gone, so each level fires
 * once. Any new row (a call, reschedule, close or drop) supersedes it, so
 * the next date starts again from level 0. Each run sends ONE grouped bell
 * notification per supervisor per level, never one per follow-up.
 */
class FollowUpEscalationService
{
    public const LEVEL_ONE_HOURS = 48;

    public const LEVEL_TWO_DAYS = 7;

    /** How many callers a grouped notification names before "+N more". */
    private const NAMES_SHOWN = 4;

    public function __construct(private FollowUpMonitorService $monitor) {}

    /**
     * Escalates every open follow-up that has crossed a level since the last
     * run. Returns how many follow-ups were escalated.
     */
    public function escalate(?Carbon $now = null): int
    {
        $now ??= now();

        $candidates = FollowUp::query()
            ->latestPerSubject()
            ->scheduled()
            ->whereNotNull('employee_id')
            ->where('next_follow_up_date', '<', $now->copy()->subHours(self::LEVEL_ONE_HOURS))
            ->where('escalation_level', '<', 2)
            ->get(['id', 'employee_id', 'next_follow_up_date', 'escalation_level']);

        if ($candidates->isEmpty()) {
            return 0;
        }

        $tree = ReportingTree::load();

        /** @var array<int, array<int, Collection<int, FollowUp>>> $byRecipient level => boss id => follow-ups */
        $byRecipient = [1 => [], 2 => []];
        $newLevels = [];

        foreach ($candidates as $followUp) {
            $level = $followUp->next_follow_up_date->lt($now->copy()->subDays(self::LEVEL_TWO_DAYS)) ? 2 : 1;

            if ($level <= $followUp->escalation_level) {
                continue;
            }

            $bosses = $this->activeBossesOf($tree, (int) $followUp->employee_id);

            for ($notifyLevel = $followUp->escalation_level + 1; $notifyLevel <= $level; $notifyLevel++) {
                $bossId = $bosses[$notifyLevel - 1] ?? null;

                if ($bossId !== null) {
                    $byRecipient[$notifyLevel][$bossId] ??= collect();
                    $byRecipient[$notifyLevel][$bossId]->push($followUp);
                }
            }

            $newLevels[$level][] = $followUp->id;
        }

        foreach ($byRecipient as $level => $recipients) {
            foreach ($recipients as $bossId => $followUps) {
                $this->notifyEscalation($bossId, $level, $followUps, $tree);
            }
        }

        foreach ($newLevels as $level => $ids) {
            FollowUp::query()->whereIn('id', $ids)->update(['escalation_level' => $level, 'escalated_at' => $now]);
        }

        return collect($newLevels)->flatten()->count();
    }

    /**
     * Sends each supervisor (and Admin) one summary of their team's
     * follow-ups for today. Skips anyone with nothing to report, and anyone
     * already sent today's summary. Returns how many were sent.
     */
    public function sendMorningDigests(?Carbon $now = null): int
    {
        $now ??= now();
        $sent = 0;

        $supervisors = User::query()
            ->where(fn ($query) => $query
                ->whereHas('roles', fn ($roles) => $roles->where('name', 'Admin'))
                ->orWhereHas('employee', fn ($employee) => $employee
                    ->whereIn('designation', [
                        Employee::DESIGNATION_TEAM_LEADER,
                        Employee::DESIGNATION_MANAGER,
                        Employee::DESIGNATION_CLUSTER,
                        Employee::DESIGNATION_BUSINESS_HEAD,
                    ])
                    ->where(fn ($active) => $active->whereNull('exit_status')->orWhere('exit_status', '!=', 'yes'))))
            ->get();

        foreach ($supervisors as $supervisor) {
            $digest = $this->digestFor($supervisor, $now);

            if ($digest === null) {
                continue;
            }

            if (! Cache::add('follow-up-digest:'.$supervisor->id.':'.$now->toDateString(), true, $now->copy()->endOfDay())) {
                continue;
            }

            Notification::make()
                ->title('Follow-up summary — '.$now->format('d M'))
                ->body(implode(' · ', $digest))
                ->icon(NotificationCategory::FollowUp->icon())
                ->iconColor('info')
                ->viewData([...NotificationCategory::FollowUp->viewData(), 'follow_up_digest' => $now->toDateString()])
                ->actions($this->monitorAction())
                ->sendToDatabase($supervisor);

            $sent++;
        }

        return $sent;
    }

    /**
     * The lines of a supervisor's morning summary, or null when there is
     * nothing to report.
     *
     * @return list<string>|null
     */
    public function digestFor(User $supervisor, Carbon $now): ?array
    {
        $query = $this->monitor->scopedQueryFor($supervisor);

        $today = $this->monitor->openLoad($query, $now->copy()->startOfDay(), $now->copy()->endOfDay());
        $dueToday = array_sum(array_map(fn (array $days): int => array_sum($days), $today));
        $overloaded = count($this->monitor->overloadedDays($today));

        $backlog = $this->monitor->backlogAges($query, $now);
        $openOverdue = array_sum($backlog);

        // Due two days ago with nothing logged: the grace period ran out at midnight.
        $graceEndedDay = $now->copy()->subDays(2);
        $newlyMissed = $this->monitor
            ->classify($query, $graceEndedDay->copy()->startOfDay(), $graceEndedDay->copy()->endOfDay(), $now)
            ->filter(fn (FollowUp $followUp): bool => $followUp->monitor_outcome === FollowUpOutcome::Missed);

        if ($dueToday === 0 && $openOverdue === 0 && $newlyMissed->isEmpty()) {
            return null;
        }

        $tree = ReportingTree::load();

        return array_values(array_filter([
            $dueToday.' due today',
            $overloaded > 0 ? $overloaded.' '.str('caller')->plural($overloaded).' over '.FollowUpMonitorService::DAILY_CAPACITY.' today' : null,
            $newlyMissed->isNotEmpty()
                ? $newlyMissed->count().' newly missed ('.$this->callerBreakdown($newlyMissed, $tree).')'
                : null,
            $openOverdue > 0 ? $openOverdue.' open overdue, '.($openOverdue - $backlog['Under 1 day'] - $backlog['1–7 days']).' over a week' : null,
        ]));
    }

    /**
     * The owner's bosses still on the rolls, nearest first — an exited
     * Team Leader is skipped so the escalation reaches someone who works.
     *
     * @return list<int>
     */
    private function activeBossesOf(ReportingTree $tree, int $employeeId): array
    {
        return array_values(array_filter(
            $tree->ancestorIds($employeeId),
            fn (int $bossId): bool => ! $tree->isExited($bossId),
        ));
    }

    /**
     * @param  Collection<int, FollowUp>  $followUps
     */
    private function notifyEscalation(int $bossId, int $level, Collection $followUps, ReportingTree $tree): void
    {
        $recipients = User::query()->where('employee_id', $bossId)->get();

        if ($recipients->isEmpty()) {
            return;
        }

        $threshold = $level === 1 ? self::LEVEL_ONE_HOURS.' hours' : self::LEVEL_TWO_DAYS.' days';

        Notification::make()
            ->title('Escalation: '.$followUps->count().' '.str('follow-up')->plural($followUps->count()).' overdue '.$threshold.'+')
            ->body('In your team: '.$this->callerBreakdown($followUps, $tree).'. Nothing has been logged on these since their time.')
            ->icon('heroicon-o-exclamation-triangle')
            ->iconColor($level === 1 ? 'warning' : 'danger')
            ->viewData([...NotificationCategory::FollowUp->viewData(), 'follow_up_escalation_level' => $level])
            ->actions($this->monitorAction())
            ->sendToDatabase($recipients);
    }

    /**
     * "Ravi Kumar 5, Asha 3, +2 more"
     *
     * @param  Collection<int, FollowUp>  $followUps
     */
    private function callerBreakdown(Collection $followUps, ReportingTree $tree): string
    {
        $counts = $followUps
            ->countBy(fn (FollowUp $followUp): string => $tree->name((int) $followUp->employee_id) ?? 'Unassigned')
            ->sortDesc();

        $shown = $counts->take(self::NAMES_SHOWN)
            ->map(fn (int $count, string $name): string => $name.' '.$count)
            ->implode(', ');

        $more = $counts->count() - self::NAMES_SHOWN;

        return $more > 0 ? $shown.', +'.$more.' more' : $shown;
    }

    /**
     * @return array<int, Action>
     */
    private function monitorAction(): array
    {
        $panel = Filament::getCurrentPanel()?->getId() ?? (DemoContext::isActive() ? 'demo' : 'admin');

        try {
            $url = FollowUpMonitor::getUrl(panel: $panel);
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }

        return [
            Action::make('openMonitor')
                ->label('Open Follow-up Monitor')
                ->url($url)
                ->markAsRead(),
        ];
    }
}
