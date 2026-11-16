<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Promotions and demotions saved before designation rows were written.
 *
 * Each one re-slotted the team below (EditEmployee / EmployeeImporter): every
 * report got a history row remarked "Follows <name>'s designation change."
 * whose old columns hold the boss in the column of their OLD designation and
 * whose new columns hold them in the column of the NEW one. That pair is read
 * back into a 'designation' row for the boss, so the months before the change
 * are rebuilt with the designation they held then — without it their whole
 * team falls off them for those months (target and achievement read 0).
 */
return new class extends Migration
{
    /**
     * Reporting column => the designation of the boss held in it
     * (Employee::REPORTING_COLUMNS, frozen here as this migration ran).
     *
     * @var array<string, int>
     */
    private const COLUMN_DESIGNATIONS = [
        'superviser_id' => 3,
        'manager_id' => 2,
        'cluster_id' => 5,
        'business_head_id' => 9,
    ];

    private const REMARKS_PREFIX = 'Follows ';

    private const REMARKS_SUFFIX = "'s designation change.";

    private const BACKFILL_REMARKS = 'Backfilled from the team re-slotted by this designation change.';

    public function up(): void
    {
        $names = DB::table('employees')->pluck('emp_name', 'id');

        /** @var array<int, array<string, array<string, int>>> $votes boss id => day => "old>new" => rows */
        $votes = [];

        $rows = DB::table('employee_reporting_history')
            ->where('remarks', 'like', self::REMARKS_PREFIX.'%designation change.')
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $name = substr((string) $row->remarks, strlen(self::REMARKS_PREFIX), -strlen(self::REMARKS_SUFFIX));
            $day = substr((string) $row->effective_date, 0, 10);

            foreach (self::COLUMN_DESIGNATIONS as $oldColumn => $oldDesignation) {
                $bossId = (int) $row->{'old_'.$oldColumn};

                if ($bossId === 0 || (string) ($names[$bossId] ?? '') !== $name) {
                    continue;
                }

                foreach (self::COLUMN_DESIGNATIONS as $newColumn => $newDesignation) {
                    if ($newColumn !== $oldColumn && (int) $row->{'new_'.$newColumn} === $bossId) {
                        $change = $oldDesignation.'>'.$newDesignation;
                        $votes[$bossId][$day][$change] = ($votes[$bossId][$day][$change] ?? 0) + 1;
                    }
                }
            }
        }

        foreach ($votes as $bossId => $days) {
            ksort($days);
            $changeDays = array_keys($days);

            foreach ($changeDays as $index => $day) {
                $alreadyRecorded = DB::table('employee_reporting_history')
                    ->where('employee_id', $bossId)
                    ->where('change_type', 'designation')
                    ->whereDate('effective_date', $day)
                    ->exists();

                if ($alreadyRecorded) {
                    continue;
                }

                arsort($days[$day]);
                [$oldDesignation, $newDesignation] = array_map('intval', explode('>', (string) array_key_first($days[$day])));
                $columns = $this->reportingColumnsOn($bossId, $day);

                DB::table('employee_reporting_history')->insert([
                    'employee_id' => $bossId,
                    'old_superviser_id' => $columns['superviser_id'],
                    'old_manager_id' => $columns['manager_id'],
                    'old_cluster_id' => $columns['cluster_id'],
                    'old_business_head_id' => $columns['business_head_id'],
                    'new_superviser_id' => $columns['superviser_id'],
                    'new_manager_id' => $columns['manager_id'],
                    'new_cluster_id' => $columns['cluster_id'],
                    'new_business_head_id' => $columns['business_head_id'],
                    'old_designation' => $oldDesignation,
                    'new_designation' => $newDesignation,
                    'effective_date' => $day,
                    'effective_to' => $changeDays[$index + 1] ?? null,
                    'change_type' => 'designation',
                    'updated_by' => null,
                    'remarks' => self::BACKFILL_REMARKS,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('employee_reporting_history')
            ->where('change_type', 'designation')
            ->where('remarks', self::BACKFILL_REMARKS)
            ->delete();
    }

    /**
     * The boss's own reporting columns from $day on: their latest reporting
     * row up to that day, else today's columns. Shown on the lifecycle only.
     *
     * @return array<string, int|null>
     */
    private function reportingColumnsOn(int $employeeId, string $day): array
    {
        $line = DB::table('employee_reporting_history')
            ->where('employee_id', $employeeId)
            ->whereNotIn('change_type', ['exit', 'designation'])
            ->whereDate('effective_date', '<=', $day)
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->first();

        $source = $line ?? DB::table('employees')->where('id', $employeeId)->first();
        $columns = [];

        foreach (array_keys(self::COLUMN_DESIGNATIONS) as $column) {
            $value = $line ? $source->{'new_'.$column} : ($source->{$column} ?? null);
            $columns[$column] = $value === null ? null : (int) $value;
        }

        return $columns;
    }
};
