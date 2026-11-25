<?php

namespace App\Support;

use App\Models\Employee;
use App\Models\TargetCategory;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Every target of one calendar month, worked out in a single pass — the one
 * place target rules live (consolidated with the user on 2026-11-24).
 *
 * 1. Everybody counts as the position they hold on the month's last day
 *    (ReportingTimeline::designationOn); Admin and out-of-tree positions
 *    carry nothing.
 * 2. A caller's OWN target is their category amount for any month they
 *    were on the rolls, split by days when the category changed inside it.
 *    What they bring to the team (their TEAM target) is that same amount
 *    once they are on the rolls MIN_TARGET_DAYS calendar days that month
 *    (a joiner still on the rolls is projected to month-end), else nothing.
 *    A caller with no category, or one without a real amount, carries
 *    nothing and is listed by missingCategoryIds().
 * 3. Each day somebody is on the rolls in that position, they sit under
 *    the nearest boss still on the rolls on their own record whose position
 *    is above theirs (ReportingTree::countBossId); with nobody, under the
 *    company directly (brokenLineIds()).
 * 4. Their month target is split between those bosses by those days.
 *    A boss with fewer than MIN_TARGET_DAYS days gets nothing and the rest
 *    share it; when no boss reached MIN_TARGET_DAYS they all share it by
 *    days. So every boss's figures add up to the level above.
 * 5. A Team Leader's target is what reached them, but at least
 *    TEAM_LEADER_MIN_TARGET once they held the seat for MIN_TARGET_DAYS
 *    days on the rolls. A Manager, Cluster Manager or Business Head carries
 *    exactly what reached them. The company is everything that reached the
 *    top.
 *
 * Built once per month per request and shared through ReportingSnapshot,
 * which retires it on any write to the employees, history or target
 * category tables.
 */
final class TargetLedger
{
    /**
     * Calendar days on the rolls before a caller's target counts for the
     * team, and the days a boss needs to keep a share of somebody who
     * moved; also a Team Leader's days in the seat before the minimum
     * applies.
     */
    public const MIN_TARGET_DAYS = 10;

    /**
     * The least a Team Leader's month target can be.
     */
    public const TEAM_LEADER_MIN_TARGET = 8000000.0;

    /**
     * The receiver id standing for the company itself.
     */
    public const COMPANY = 0;

    /**
     * @var array<string, float>
     */
    private array $reachMemo = [];

    /**
     * @var array<string, bool>
     */
    private array $touchesMemo = [];

    /**
     * @param  array<int, int>  $designations  id => designation held on the month's last day
     * @param  array<int, array{start: Carbon, end: Carbon, days: int, is_partial: bool, is_projected: bool}|null>  $windows
     * @param  array<int, float>  $targets  id => month target (a caller's team target); COMPANY => company target
     * @param  array<int, float>  $teamSums  leader id => what reached them before the Team Leader minimum
     * @param  array<int, array{amount: float, missing: bool}>  $categoryTargets  caller id => category target
     * @param  array<int, array<int, int>>  $receiverDays  id => boss id (COMPANY for none) => days
     * @param  array<int, array<int, float>>  $shares  id => boss id => share of their target
     * @param  array<int, int>  $roleDays  id => days on the rolls holding the month's position
     */
    private function __construct(
        private readonly CarbonImmutable $start,
        private readonly CarbonImmutable $end,
        private readonly array $designations,
        private readonly array $windows,
        private readonly array $targets,
        private readonly array $teamSums,
        private readonly array $categoryTargets,
        private readonly array $receiverDays,
        private readonly array $shares,
        private readonly array $roleDays,
    ) {}

    /**
     * The ledger of the calendar month $month falls in.
     */
    public static function forMonth(CarbonInterface $month): self
    {
        $start = CarbonImmutable::parse($month->toDateString())->startOfMonth();

        return ReportingSnapshot::remember(
            'target-ledger:'.$start->format('Y-m').':'.Carbon::today()->toDateString(),
            fn (): self => self::build($start),
        );
    }

    public function start(): CarbonImmutable
    {
        return $this->start;
    }

    public function end(): CarbonImmutable
    {
        return $this->end;
    }

    public function days(): int
    {
        return ReportingTimeline::dayCount($this->start, $this->end);
    }

    /**
     * The position $id is counted as this month (held on its last day), or
     * null when they carry no target (Admin, out-of-tree positions).
     */
    public function designation(int $id): ?int
    {
        return $this->designations[$id] ?? null;
    }

    /**
     * $id's month target: a caller's TEAM target (what they bring to their
     * bosses), a leader's team target, or the company's for COMPANY.
     */
    public function target(int $id): float
    {
        return round($this->targets[$id] ?? 0.0, 2);
    }

    public function companyTarget(): float
    {
        return $this->target(self::COMPANY);
    }

    /**
     * The target on $id's own screen: a caller's full category amount for
     * any month they were on the rolls, regardless of joining or leaving;
     * anyone else's month target.
     */
    public function ownTarget(int $id): float
    {
        if (($this->designations[$id] ?? null) !== Employee::DESIGNATION_CALLER) {
            return $this->target($id);
        }

        return ($this->windows[$id] ?? null) === null ? 0.0 : $this->categoryTarget($id);
    }

    /**
     * A caller's category amount for the month (split by days when it
     * changed inside it), 0 when they have none.
     */
    public function categoryTarget(int $id): float
    {
        return round($this->categoryTargets[$id]['amount'] ?? 0.0, 2);
    }

    /**
     * Whether a caller on the rolls this month lacks a category with a real
     * amount on some day of it.
     */
    public function hasMissingCategory(int $id): bool
    {
        return ($this->windows[$id] ?? null) !== null && ($this->categoryTargets[$id]['missing'] ?? false);
    }

    /**
     * What reached leader $id from below, before the Team Leader minimum.
     */
    public function teamSum(int $id): float
    {
        return round($this->teamSums[$id] ?? 0.0, 2);
    }

    /**
     * What the Team Leader minimum added to $id's target.
     */
    public function floorTopUp(int $id): float
    {
        if (! isset($this->teamSums[$id])) {
            return 0.0;
        }

        return round(max(($this->targets[$id] ?? 0.0) - $this->teamSums[$id], 0.0), 2);
    }

    /**
     * The part of $id's month target that counts in $bossId's (the company
     * for COMPANY) — their whole target when $id is $bossId.
     */
    public function contribution(int $id, int $bossId): float
    {
        if ($id === $bossId) {
            return $this->target($id);
        }

        return round(($this->targets[$id] ?? 0.0) * $this->reach($id, $bossId), 2);
    }

    /**
     * Everyone who sat under $bossId (the whole company for COMPANY) on at
     * least one day of the month while on the rolls, of one position when
     * $designation is given.
     *
     * @return list<int>
     */
    public function memberIds(int $bossId, ?int $designation = null): array
    {
        $ids = [];

        foreach ($this->designations as $id => $memberDesignation) {
            if ($id === $bossId || ($designation !== null && $memberDesignation !== $designation)) {
                continue;
            }

            if ($this->touches($id, $bossId)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * What each of $bossId's members brings to them.
     *
     * @return array<int, float> member id => contribution
     */
    public function contributions(int $bossId, ?int $designation = null): array
    {
        $contributions = [];

        foreach ($this->memberIds($bossId, $designation) as $id) {
            $contributions[$id] = $this->contribution($id, $bossId);
        }

        return $contributions;
    }

    /**
     * The Team Leader minimum top-ups counted in $bossId's target, by the
     * Team Leader they belong to — $bossId's own included.
     *
     * @return array<int, float> team leader id => top-up counted for $bossId
     */
    public function floorContributions(int $bossId): array
    {
        $topUps = [];

        foreach (array_keys($this->teamSums) as $id) {
            $topUp = $this->floorTopUp($id);

            if ($topUp <= 0) {
                continue;
            }

            $counted = $id === $bossId ? $topUp : round($topUp * $this->reach($id, $bossId), 2);

            if ($counted > 0) {
                $topUps[$id] = $counted;
            }
        }

        return $topUps;
    }

    /**
     * Days $id spent under each boss this month while on the rolls in the
     * month's position (COMPANY: under nobody).
     *
     * @return array<int, int>
     */
    public function receiverDays(int $id): array
    {
        return $this->receiverDays[$id] ?? [];
    }

    /**
     * The share of $id's target each of their bosses carries.
     *
     * @return array<int, float>
     */
    public function shares(int $id): array
    {
        return $this->shares[$id] ?? [];
    }

    /**
     * Days $id was on the rolls holding the month's position.
     */
    public function roleDays(int $id): int
    {
        return $this->roleDays[$id] ?? 0;
    }

    /**
     * $id's on-rolls window this month (OnRollsWindow), null when not on
     * the rolls at all.
     *
     * @return array{start: Carbon, end: Carbon, days: int, is_partial: bool, is_projected: bool}|null
     */
    public function window(int $id): ?array
    {
        return $this->windows[$id] ?? null;
    }

    /**
     * Callers, Team Leaders and Managers on the rolls who sat under nobody
     * on some day of the month — every boss on their record had left — so
     * their target reached only the company total.
     *
     * @return list<int>
     */
    public function brokenLineIds(): array
    {
        $clusterRank = Employee::designationRank(Employee::DESIGNATION_CLUSTER);

        return array_values(array_filter(
            array_keys($this->receiverDays),
            fn (int $id): bool => ($this->receiverDays[$id][self::COMPANY] ?? 0) > 0
                && Employee::designationRank($this->designations[$id] ?? null) < $clusterRank,
        ));
    }

    /**
     * Callers on the rolls this month with no category carrying a real
     * amount.
     *
     * @return list<int>
     */
    public function missingCategoryIds(): array
    {
        return array_values(array_filter(
            array_keys($this->categoryTargets),
            fn (int $id): bool => $this->hasMissingCategory($id),
        ));
    }

    /**
     * The caller team-target rule applied to $employee for the month $month
     * falls in, whatever position the ledger counts them as: their category
     * amount once on the rolls MIN_TARGET_DAYS days, else nothing.
     */
    public static function callerTeamTarget(Employee $employee, CarbonInterface $month): float
    {
        $start = CarbonImmutable::parse($month->toDateString())->startOfMonth();
        $window = OnRollsWindow::forMonth($employee, $start, $start->endOfMonth());

        if ($window === null || $window['days'] < self::MIN_TARGET_DAYS) {
            return 0.0;
        }

        return round(self::categoryTargetOf($employee, $start, $start->endOfMonth())['amount'], 2);
    }

    private static function build(CarbonImmutable $start): self
    {
        $end = $start->endOfMonth();
        $timeline = ReportingTimeline::forMonth($start);
        $employees = self::employees();

        $designations = [];
        $windows = [];
        $windowDays = [];

        foreach ($employees as $id => $employee) {
            $designation = ReportingTimeline::designationOn($employee, $end);

            if (Employee::designationRank($designation) === 0) {
                continue;
            }

            $window = OnRollsWindow::forMonth($employee, $start, $end);

            $designations[$id] = $designation;
            $windows[$id] = $window;
            $windowDays[$id] = $window === null ? null : [self::dayNumber($window['start']), self::dayNumber($window['end'])];
        }

        $receiverDays = [];
        $roleDays = [];

        foreach ($timeline->segments() as $segment) {
            $tree = $segment['tree'];
            $segmentStart = self::dayNumber($segment['start']);
            $segmentEnd = self::dayNumber($segment['end']);

            foreach ($designations as $id => $designation) {
                if ($windowDays[$id] === null || $tree->designation($id) !== $designation) {
                    continue;
                }

                // A leader the tree has as gone that day (an exit with no
                // date counts as gone on every day) leads nobody and keeps
                // no Team Leader minimum. A caller counts either way.
                if ($designation !== Employee::DESIGNATION_CALLER && $tree->isExited($id)) {
                    continue;
                }

                $days = min($segmentEnd, $windowDays[$id][1]) - max($segmentStart, $windowDays[$id][0]) + 1;

                if ($days <= 0) {
                    continue;
                }

                $receiver = self::receiverOn($tree, $id, $designations);

                $receiverDays[$id][$receiver] = ($receiverDays[$id][$receiver] ?? 0) + $days;
                $roleDays[$id] = ($roleDays[$id] ?? 0) + $days;
            }
        }

        $lastTree = $timeline->treeOn($end);
        $order = array_keys($designations);

        usort($order, fn (int $a, int $b): int => [Employee::designationRank($designations[$a]), $a] <=> [Employee::designationRank($designations[$b]), $b]);

        $targets = [];
        $teamSums = [];
        $categoryTargets = [];
        $shares = [];
        $incoming = [];

        foreach ($order as $id) {
            if ($designations[$id] === Employee::DESIGNATION_CALLER) {
                $categoryTargets[$id] = self::categoryTargetOf($employees[$id], $start, $end);

                $target = $windows[$id] !== null && $windows[$id]['days'] >= self::MIN_TARGET_DAYS
                    ? $categoryTargets[$id]['amount']
                    : 0.0;
            } else {
                $teamSums[$id] = $incoming[$id] ?? 0.0;

                $target = $designations[$id] === Employee::DESIGNATION_TEAM_LEADER && ($roleDays[$id] ?? 0) >= self::MIN_TARGET_DAYS
                    ? max($teamSums[$id], self::TEAM_LEADER_MIN_TARGET)
                    : $teamSums[$id];
            }

            $targets[$id] = $target;

            $shares[$id] = isset($receiverDays[$id])
                ? self::split($receiverDays[$id])
                // Never on the rolls in this position, yet something reached
                // them: pass it on to whoever they sat under at month-end.
                : ($target > 0 ? [self::receiverOn($lastTree, $id, $designations) => 1.0] : []);

            foreach ($shares[$id] as $receiver => $share) {
                $incoming[$receiver] = ($incoming[$receiver] ?? 0.0) + $target * $share;
            }
        }

        $targets[self::COMPANY] = $incoming[self::COMPANY] ?? 0.0;

        return new self($start, $end, $designations, $windows, $targets, $teamSums, $categoryTargets, $receiverDays, $shares, $roleDays);
    }

    /**
     * The boss $id counts under on the day $tree stands for: the nearest
     * one still on the rolls on their own record, passing over anyone whose
     * position this month is not above theirs; COMPANY when there is none.
     *
     * @param  array<int, int>  $designations
     */
    private static function receiverOn(ReportingTree $tree, int $id, array $designations): int
    {
        $rank = Employee::designationRank($designations[$id] ?? null);

        for ($bossId = $tree->countBossId($id); $bossId !== null; $bossId = $tree->countBossId($bossId)) {
            if (Employee::designationRank($designations[$bossId] ?? null) > $rank) {
                return $bossId;
            }
        }

        return self::COMPANY;
    }

    /**
     * Shares of a month target by the days spent under each boss: bosses
     * with MIN_TARGET_DAYS or more split it by their days, or — when none
     * reached it — every boss does.
     *
     * @param  array<int, int>  $days  boss id => days
     * @return array<int, float> boss id => share
     */
    private static function split(array $days): array
    {
        $qualifying = array_filter($days, fn (int $count): bool => $count >= self::MIN_TARGET_DAYS);
        $base = $qualifying === [] ? $days : $qualifying;
        $total = array_sum($base);

        if ($total <= 0) {
            return [];
        }

        return array_map(fn (int $count): float => $count / $total, $base);
    }

    /**
     * A caller's category target for the month: the amount in force that
     * month of the category held on each day, split by days when it
     * changed inside the month. Days with no category, or one without a
     * real amount, carry nothing and mark the target as missing.
     *
     * @return array{amount: float, missing: bool}
     */
    private static function categoryTargetOf(Employee $employee, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $spans = TargetCategoryTimeline::spans($employee, $start, $end);

        if (count($spans) <= 1) {
            $amount = TargetCategory::targetAmountFor($spans[0]['category'] ?? $employee->category, $start);

            return TargetCategory::isTargetAmount($amount)
                ? ['amount' => (float) $amount, 'missing' => false]
                : ['amount' => 0.0, 'missing' => true];
        }

        $daysInMonth = $start->daysInMonth;
        $total = 0.0;
        $missing = false;

        foreach ($spans as $span) {
            $amount = TargetCategory::targetAmountFor($span['category'], $start);

            if (! TargetCategory::isTargetAmount($amount)) {
                $missing = true;

                continue;
            }

            $total += $amount * $span['days'] / $daysInMonth;
        }

        return ['amount' => round($total, 2), 'missing' => $missing];
    }

    /**
     * Every employee with the columns a target is read from, keyed by id
     * and shared for the request.
     *
     * @return Collection<int, Employee>
     */
    private static function employees(): Collection
    {
        return ReportingSnapshot::remember('target-ledger-employees', fn (): Collection => Employee::query()
            ->get(['id', 'emp_name', 'designation', 'category', 'reporting_date', 'exit_status', 'exit_date'])
            ->keyBy('id'));
    }

    private static function dayNumber(CarbonInterface $day): int
    {
        return intdiv((int) strtotime($day->toDateString().' 00:00:00 UTC'), 86400);
    }

    /**
     * Share of $id's target that ends up in $bossId's.
     */
    private function reach(int $id, int $bossId): float
    {
        $key = $id.':'.$bossId;

        if (isset($this->reachMemo[$key])) {
            return $this->reachMemo[$key];
        }

        $reach = 0.0;

        foreach ($this->shares[$id] ?? [] as $receiver => $share) {
            if ($receiver === $bossId) {
                $reach += $share;
            } elseif ($receiver !== self::COMPANY) {
                $reach += $share * $this->reach($receiver, $bossId);
            }
        }

        return $this->reachMemo[$key] = $reach;
    }

    /**
     * Whether $id sat under $bossId — directly or through somebody in
     * between — on at least one day, whatever share that brought.
     */
    private function touches(int $id, int $bossId): bool
    {
        $key = $id.':'.$bossId;

        if (isset($this->touchesMemo[$key])) {
            return $this->touchesMemo[$key];
        }

        $this->touchesMemo[$key] = false;

        foreach (array_keys(($this->receiverDays[$id] ?? []) + ($this->shares[$id] ?? [])) as $receiver) {
            if ($receiver === $bossId || ($receiver !== self::COMPANY && $this->touches($receiver, $bossId))) {
                return $this->touchesMemo[$key] = true;
            }
        }

        return false;
    }
}
