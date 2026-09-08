<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Docs Received" has been dropped from the Daily Commitment ladder,
 * which now reads OTP -> SFL -> Underwriting -> Approval -> Disbursal.
 *
 * Any row still carrying the retired value would throw on load, because
 * CommitmentStage no longer has a case for it, so every stored
 * 'docs_received' is lifted to the rung that replaced it at the bottom of
 * the amount ladder: SFL. Lifting rather than dropping is deliberate —
 * zeroing a stage would silently rewrite what somebody committed to or
 * declared, whereas SFL is the nearest rung the LMS can actually prove.
 */
return new class extends Migration
{
    /**
     * Every column in the module that stores a CommitmentStage value.
     *
     * @var array<string, array<int, string>>
     */
    private const STAGE_COLUMNS = [
        'daily_commitments' => ['commitment_stage', 'current_stage'],
        'daily_commitment_entries' => ['stage', 'lms_highest_stage', 'outcome'],
        'monthly_commitment_targets' => ['stage'],
        'daily_commitment_logs' => ['old_stage', 'new_stage'],
    ];

    public function up(): void
    {
        $this->rewrite('docs_received', 'sfl');
    }

    /**
     * Irreversible in substance: once lifted to SFL there is no record of
     * which rows were originally Docs Received. The down path is left as a
     * no-op rather than pretending otherwise.
     */
    public function down(): void
    {
        //
    }

    private function rewrite(string $from, string $to): void
    {
        foreach (self::STAGE_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                DB::table($table)->where($column, $from)->update([$column => $to]);
            }
        }
    }
};
