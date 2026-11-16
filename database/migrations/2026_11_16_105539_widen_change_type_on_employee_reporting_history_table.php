<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * change_type was created as an ENUM of joining / reporting_change /
 * promotion / transfer / exit, so MySQL refused the 'designation' rows
 * (ReportingLineService::CHANGE_DESIGNATION) the next migration backfills —
 * "Data truncated for column 'change_type'". SQLite, which the tests run on,
 * never enforced the list.
 *
 * A plain string from here on, so a new kind of history row never needs
 * another schema change. Named to run after 105539 (already applied on
 * production) and before the 105540 backfill.
 */
return new class extends Migration
{
    /** The values the original ENUM allowed. */
    private const ORIGINAL_TYPES = ['joining', 'reporting_change', 'promotion', 'transfer', 'exit'];

    public function up(): void
    {
        Schema::table('employee_reporting_history', function (Blueprint $table) {
            $table->string('change_type', 32)->default('reporting_change')->change();
        });
    }

    public function down(): void
    {
        DB::table('employee_reporting_history')->whereNotIn('change_type', self::ORIGINAL_TYPES)->delete();

        Schema::table('employee_reporting_history', function (Blueprint $table) {
            $table->enum('change_type', self::ORIGINAL_TYPES)->default('reporting_change')->change();
        });
    }
};
