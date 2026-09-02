<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->date('disbursal_date')->nullable()->after('disbursal_finalized');
        });

        // Backfill: customers already in the disbursed stage have no
        // disbursal_date yet (the column didn't exist), so the header
        // month filter would drop them from every month. Default their
        // disbursal_date to their approval_date until corrected manually.
        DB::table('customers')
            ->where('disbursal_status', 'disbursed')
            ->whereNull('disbursal_date')
            ->whereNotNull('approval_date')
            ->update(['disbursal_date' => DB::raw('approval_date')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('disbursal_date');
        });
    }
};
