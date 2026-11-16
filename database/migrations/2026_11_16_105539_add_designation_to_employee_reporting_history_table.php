<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A promotion or demotion is dated like a reporting change, so a past
 * month is rebuilt with the designation everybody held in it
 * (ReportingTimeline). Written on 'designation' rows only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_reporting_history', function (Blueprint $table) {
            $table->unsignedInteger('old_designation')->nullable()->after('new_business_head_id');
            $table->unsignedInteger('new_designation')->nullable()->after('old_designation');
        });
    }

    public function down(): void
    {
        DB::table('employee_reporting_history')->where('change_type', 'designation')->delete();

        Schema::table('employee_reporting_history', function (Blueprint $table) {
            $table->dropColumn(['old_designation', 'new_designation']);
        });
    }
};
