<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Dates the October 2026 restructure as it really happened (decided with
 * the user 2026-11-16, from the live employee_reporting_history).
 *
 * Cluster Managers Kanak Kumar and Kunwar Pratap Singh left on 30 Sep. Their
 * four Managers took over as Cluster Managers from 1 Oct, reporting to the
 * Business Head, but the change was keyed in on 14 Nov, so every month figure
 * treated 1 Oct – 13 Nov as still under the two who had left. This moves:
 *
 *  - the four promotions — their own reporting row, their 'designation' row
 *    (2026_11_16_105540) and the re-slot of their team — to 1 Oct; reporting
 *    lines in force between 1 Oct and 14 Nov that still filed one of them as
 *    a Manager are filed under them as Cluster Manager;
 *  - Ronit Tomar's move under Nitin Kumar to 1 Oct, and Vaibhav Agrawal's
 *    under Anuj Singh Thakur to the day he joined (19 Oct);
 *  - exited employees who reported straight to one of the four: their columns
 *    were re-slotted on 14 Nov without a history row, so the line before
 *    1 Oct (the four were Managers then) is recorded for them;
 *  - Deepak Singh into Kunwar Pratap Singh's cluster from 1 Sep and Prabhat
 *    Tyagi from 2 Sep — both moves had been made without a history row;
 *  - Rohit Sharma (left 6 Aug) to the Cluster Manager seat he held.
 *
 * Every step finds people by emp_id and only acts on the rows it expects, so
 * on any other database it changes nothing. One-way: there is no down().
 */
return new class extends Migration
{
    private const RESTRUCTURE_DAY = '2026-10-01';

    private const RECORDED_DAY = '2026-11-14';

    /** Managers promoted to Cluster Manager in the restructure. */
    private const PROMOTED = ['ME0009', 'ME0045', 'ME0161', 'ME0194'];

    /** emp_id => [new boss emp_id, day the move counts from; null = their reporting date]. */
    private const RESTRUCTURE_MOVES = [
        'ME0271' => ['ME0009', self::RESTRUCTURE_DAY],
        'ME0294' => ['ME0045', null],
    ];

    private const KANAK = 'ME0006';

    private const KUNWAR = 'ME0008';

    /** emp_id => day they moved from Kanak's cluster to Kunwar's. */
    private const MOVES_TO_KUNWAR = [
        'ME0161' => '2026-09-01',
        'ME0033' => '2026-09-02',
    ];

    private const ROHIT_SHARMA = 'ME0148';

    private const DESIGNATION_MANAGER = 2;

    private const DESIGNATION_CLUSTER = 5;

    private const COLUMNS = ['superviser_id', 'manager_id', 'cluster_id', 'business_head_id'];

    private const REMARKS = 'Recorded on 2026-11-16 from the October 2026 restructure.';

    /** @var array<int, true> employees whose history this migration touched */
    private array $touched = [];

    public function up(): void
    {
        DB::transaction(function (): void {
            $promotedIds = $this->ids(self::PROMOTED);

            foreach ($promotedIds as $bossId) {
                $this->datePromotion($bossId);
            }

            foreach (self::RESTRUCTURE_MOVES as $empId => [$newBossEmpId, $day]) {
                $this->dateRestructureMove($empId, $newBossEmpId, $day);
            }

            foreach (self::MOVES_TO_KUNWAR as $empId => $day) {
                $this->recordMoveToKunwar($empId, $day);
            }

            DB::table('employees')
                ->where('emp_id', self::ROHIT_SHARMA)
                ->where('designation', self::DESIGNATION_MANAGER)
                ->update(['designation' => self::DESIGNATION_CLUSTER, 'updated_at' => now()]);

            foreach (array_keys($this->touched) as $employeeId) {
                $this->rechain($employeeId);
            }
        });
    }

    public function down(): void
    {
        // A one-way data correction: the dates it replaces were wrong.
    }

    /**
     * Move one promotion, and everything that followed from it, from
     * 14 Nov to 1 Oct.
     */
    private function datePromotion(int $bossId): void
    {
        $boss = DB::table('employees')->where('id', $bossId)->first();

        $ownChange = $this->reportingRows($bossId)
            ->first(fn (object $row): bool => $this->day($row) === self::RECORDED_DAY
                && $row->new_manager_id === null
                && $row->new_cluster_id === null
                && $row->new_business_head_id !== null);

        if (! $boss || (int) $boss->designation !== self::DESIGNATION_CLUSTER || ! $ownChange) {
            return;
        }

        $this->moveRow($ownChange->id, self::RESTRUCTURE_DAY);
        $this->datePromotionRow($bossId);

        $businessHeadId = $boss->business_head_id === null ? null : (int) $boss->business_head_id;

        $memberIds = DB::table('employee_reporting_history')
            ->where('employee_id', '!=', $bossId)
            ->where(fn ($query) => $query
                ->where('old_manager_id', $bossId)
                ->orWhere('new_manager_id', $bossId)
                ->orWhere('new_cluster_id', $bossId))
            ->distinct()
            ->pluck('employee_id')
            ->merge(DB::table('employees')->where('id', '!=', $bossId)->where('cluster_id', $bossId)->pluck('id'))
            ->map(fn ($id): int => (int) $id)
            ->unique();

        foreach ($memberIds as $memberId) {
            $this->refileMember($memberId, $bossId, $businessHeadId);
        }
    }

    /**
     * The boss's 'designation' row, dated 1 Oct — written here if the
     * backfill found no team re-slot to read it from.
     */
    private function datePromotionRow(int $bossId): void
    {
        $rows = DB::table('employee_reporting_history')
            ->where('employee_id', $bossId)
            ->where('change_type', 'designation')
            ->get();

        foreach ($rows->filter(fn (object $row): bool => $this->day($row) === self::RECORDED_DAY) as $row) {
            $this->moveRow($row->id, self::RESTRUCTURE_DAY);
        }

        $alreadyDated = $rows->contains(fn (object $row): bool => in_array($this->day($row), [self::RECORDED_DAY, self::RESTRUCTURE_DAY], true)
            && (int) $row->new_designation === self::DESIGNATION_CLUSTER);

        if ($alreadyDated) {
            return;
        }

        $line = $this->columnsOf(DB::table('employees')->where('id', $bossId)->first(), '');

        $this->insertRow($bossId, self::RESTRUCTURE_DAY, $line, $line, 'designation', [
            'old_designation' => self::DESIGNATION_MANAGER,
            'new_designation' => self::DESIGNATION_CLUSTER,
        ]);
    }

    /**
     * File $memberId under $bossId as Cluster Manager from 1 Oct.
     */
    private function refileMember(int $memberId, int $bossId, ?int $businessHeadId): void
    {
        $rows = $this->reportingRows($memberId);

        $reslots = $rows->filter(fn (object $row): bool => $this->day($row) === self::RECORDED_DAY
            && (int) $row->old_manager_id === $bossId
            && $row->old_superviser_id === $row->new_superviser_id);

        $betweenDays = $rows->filter(fn (object $row): bool => $this->day($row) >= self::RESTRUCTURE_DAY
            && $this->day($row) < self::RECORDED_DAY);

        if ($reslots->isNotEmpty() && $betweenDays->isEmpty()) {
            // Nothing else happened to them in between: the re-slot itself
            // simply took effect on 1 Oct.
            foreach ($reslots as $row) {
                $this->moveRow($row->id, self::RESTRUCTURE_DAY);
            }
        } else {
            $this->splitLineOnRestructureDay($memberId, $rows, $bossId, $businessHeadId);
        }

        // Every line from 1 Oct on files the boss as their Cluster Manager.
        foreach ($this->reportingRows($memberId) as $row) {
            $day = $this->day($row);

            if ($day < self::RESTRUCTURE_DAY || $day > self::RECORDED_DAY) {
                continue;
            }

            $changes = [];

            if ((int) $row->new_manager_id === $bossId) {
                $changes += ['new_manager_id' => null, 'new_cluster_id' => $bossId, 'new_business_head_id' => $businessHeadId];
            }

            if ($day > self::RESTRUCTURE_DAY && (int) $row->old_manager_id === $bossId) {
                $changes += ['old_manager_id' => null, 'old_cluster_id' => $bossId, 'old_business_head_id' => $businessHeadId];
            }

            if ($changes !== []) {
                $this->updateRow($row->id, $memberId, $changes);
            }
        }
    }

    /**
     * Start a new line on 1 Oct when the one in force that day still files
     * $bossId as their direct Manager. Someone with no history row from
     * then on (an exited report re-slotted without one) gets the line
     * before as that row's old columns.
     *
     * @param  Collection<int, object>  $rows
     */
    private function splitLineOnRestructureDay(int $memberId, $rows, int $bossId, ?int $businessHeadId): void
    {
        if ($rows->contains(fn (object $row): bool => $this->day($row) === self::RESTRUCTURE_DAY)) {
            return;
        }

        $before = $rows->filter(fn (object $row): bool => $this->day($row) < self::RESTRUCTURE_DAY)->last();
        $hasLaterRow = $rows->contains(fn (object $row): bool => $this->day($row) > self::RESTRUCTURE_DAY);

        if ($hasLaterRow) {
            if (! $before || (int) $before->new_manager_id !== $bossId || $before->new_superviser_id !== null) {
                return;
            }

            $old = $this->columnsOf($before, 'new_');
            $new = ['manager_id' => null, 'cluster_id' => $bossId, 'business_head_id' => $businessHeadId] + $old;

            $this->insertRow($memberId, self::RESTRUCTURE_DAY, $old, $new);

            return;
        }

        // No row after 1 Oct, so today's columns have held for every day
        // since their last row: only a straight report of the boss is
        // affected, the boss being a Manager before 1 Oct.
        $today = $this->columnsOf(DB::table('employees')->where('id', $memberId)->first(), '');

        if ($today['superviser_id'] !== null || $today['manager_id'] !== null || $today['cluster_id'] !== $bossId) {
            return;
        }

        $old = [
            'superviser_id' => null,
            'manager_id' => $bossId,
            'cluster_id' => $before && (int) $before->new_manager_id === $bossId ? $this->columnsOf($before, 'new_')['cluster_id'] : null,
            'business_head_id' => $before ? $this->columnsOf($before, 'new_')['business_head_id'] : null,
        ];

        // A joining row that recorded no boss at all would otherwise become
        // their line before 1 Oct.
        if ($before && array_filter($this->columnsOf($before, 'new_')) === []) {
            $this->updateRow($before->id, $memberId, $this->prefixed($old, 'new_'));
        }

        $this->insertRow($memberId, self::RESTRUCTURE_DAY, $old, $today);
    }

    /**
     * Date a move made on 14 Nov as part of the restructure from $day
     * (their reporting date when null).
     */
    private function dateRestructureMove(string $empId, string $newBossEmpId, ?string $day): void
    {
        $employee = DB::table('employees')->where('emp_id', $empId)->first();
        $newBossId = $this->ids([$newBossEmpId])[0] ?? null;

        if (! $employee || ! $newBossId) {
            return;
        }

        $day ??= substr((string) $employee->reporting_date, 0, 10);

        if ($day < self::RESTRUCTURE_DAY || $day >= self::RECORDED_DAY) {
            return;
        }

        $move = $this->reportingRows((int) $employee->id)
            ->first(fn (object $row): bool => $this->day($row) === self::RECORDED_DAY && (int) $row->new_cluster_id === $newBossId);

        if ($move) {
            $this->moveRow($move->id, $day);
        }
    }

    /**
     * Record a move from Kanak's cluster to Kunwar's that was made without
     * a history row.
     */
    private function recordMoveToKunwar(string $empId, string $day): void
    {
        $employeeId = $this->ids([$empId])[0] ?? null;
        [$kanakId, $kunwarId] = [$this->ids([self::KANAK])[0] ?? null, $this->ids([self::KUNWAR])[0] ?? null];

        if (! $employeeId || ! $kanakId || ! $kunwarId) {
            return;
        }

        $rows = $this->reportingRows($employeeId);
        $before = $rows->filter(fn (object $row): bool => $this->day($row) < $day)->last();

        if (! $before
            || (int) $before->new_cluster_id !== $kanakId
            || $rows->contains(fn (object $row): bool => $this->day($row) === $day)) {
            return;
        }

        $old = $this->columnsOf($before, 'new_');

        $this->insertRow($employeeId, $day, $old, ['cluster_id' => $kunwarId] + $old);
    }

    /**
     * effective_to of each line = the day the next one starts; the last
     * line ends at the exit, if any.
     */
    private function rechain(int $employeeId): void
    {
        $rows = $this->reportingRows($employeeId)->values();
        $exitDay = DB::table('employee_reporting_history')
            ->where('employee_id', $employeeId)
            ->where('change_type', 'exit')
            ->orderByDesc('effective_date')
            ->value('effective_date');

        foreach ($rows as $index => $row) {
            $end = isset($rows[$index + 1])
                ? $this->day($rows[$index + 1])
                : ($exitDay === null ? null : substr((string) $exitDay, 0, 10));

            if ($row->effective_to === null ? $end !== null : substr((string) $row->effective_to, 0, 10) !== $end) {
                DB::table('employee_reporting_history')->where('id', $row->id)->update(['effective_to' => $end, 'updated_at' => now()]);
            }
        }

        $designationRows = DB::table('employee_reporting_history')
            ->where('employee_id', $employeeId)
            ->where('change_type', 'designation')
            ->orderBy('effective_date')
            ->orderBy('id')
            ->get()
            ->values();

        foreach ($designationRows as $index => $row) {
            DB::table('employee_reporting_history')->where('id', $row->id)->update([
                'effective_to' => isset($designationRows[$index + 1]) ? $this->day($designationRows[$index + 1]) : null,
            ]);
        }
    }

    /**
     * An employee's reporting lines (not exits, not designation rows),
     * oldest first — the order ReportingTimeline reads them in.
     *
     * @return Collection<int, object>
     */
    private function reportingRows(int $employeeId)
    {
        return DB::table('employee_reporting_history')
            ->where('employee_id', $employeeId)
            ->whereNotIn('change_type', ['exit', 'designation'])
            ->orderBy('effective_date')
            ->orderBy('id')
            ->get()
            ->map(function (object $row): object {
                foreach (self::COLUMNS as $column) {
                    foreach (['old_', 'new_'] as $prefix) {
                        $value = $row->{$prefix.$column};
                        $row->{$prefix.$column} = $value === null ? null : (int) $value;
                    }
                }

                return $row;
            });
    }

    private function moveRow(int $rowId, string $day): void
    {
        $row = DB::table('employee_reporting_history')->where('id', $rowId)->first();

        DB::table('employee_reporting_history')->where('id', $rowId)->update(['effective_date' => $day, 'updated_at' => now()]);

        $this->touched[(int) $row->employee_id] = true;
    }

    /**
     * @param  array<string, int|null>  $changes
     */
    private function updateRow(int $rowId, int $employeeId, array $changes): void
    {
        DB::table('employee_reporting_history')->where('id', $rowId)->update($changes + ['updated_at' => now()]);

        $this->touched[$employeeId] = true;
    }

    /**
     * @param  array<string, int|null>  $old
     * @param  array<string, int|null>  $new
     * @param  array<string, int>  $extra
     */
    private function insertRow(int $employeeId, string $day, array $old, array $new, string $changeType = 'reporting_change', array $extra = []): void
    {
        DB::table('employee_reporting_history')->insert([
            'employee_id' => $employeeId,
            ...$this->prefixed($old, 'old_'),
            ...$this->prefixed($new, 'new_'),
            'effective_date' => $day,
            'effective_to' => null,
            'change_type' => $changeType,
            'updated_by' => null,
            'remarks' => self::REMARKS,
            'created_at' => now(),
            'updated_at' => now(),
            ...$extra,
        ]);

        $this->touched[$employeeId] = true;
    }

    /**
     * @return array<string, int|null>
     */
    private function columnsOf(?object $source, string $prefix): array
    {
        $columns = [];

        foreach (self::COLUMNS as $column) {
            $value = $source?->{$prefix.$column} ?? null;
            $columns[$column] = $value === null ? null : (int) $value;
        }

        return $columns;
    }

    /**
     * @param  array<string, int|null>  $columns
     * @return array<string, int|null>
     */
    private function prefixed(array $columns, string $prefix): array
    {
        $prefixed = [];

        foreach (self::COLUMNS as $column) {
            $prefixed[$prefix.$column] = $columns[$column] ?? null;
        }

        return $prefixed;
    }

    /**
     * @param  list<string>  $empIds
     * @return list<int>
     */
    private function ids(array $empIds): array
    {
        return DB::table('employees')
            ->whereIn('emp_id', $empIds)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    private function day(object $row): string
    {
        return substr((string) $row->effective_date, 0, 10);
    }
};
