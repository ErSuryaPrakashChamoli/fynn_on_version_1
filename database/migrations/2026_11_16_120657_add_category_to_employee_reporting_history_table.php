<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A target category change is dated like a promotion, so a past month keeps
 * the target category held in it (TargetCategoryTimeline). Written on
 * 'target_category' rows only; values are employees.category codes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_reporting_history', function (Blueprint $table) {
            $table->string('old_category')->nullable()->after('new_designation');
            $table->string('new_category')->nullable()->after('old_category');
        });
    }

    public function down(): void
    {
        DB::table('employee_reporting_history')->where('change_type', 'target_category')->delete();

        Schema::table('employee_reporting_history', function (Blueprint $table) {
            $table->dropColumn(['old_category', 'new_category']);
        });
    }
};
