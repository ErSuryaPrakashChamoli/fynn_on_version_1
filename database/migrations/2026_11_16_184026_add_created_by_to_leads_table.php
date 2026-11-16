<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who created a lead, kept apart from employee_id (who holds it now, which a
 * hand-over changes). A Team Leader or above who moves to a new boss takes
 * along only the open leads they created (EmployeeDependencyService).
 *
 * Nothing recorded the creator before, so existing leads are backfilled
 * with their current holder — the creator for every lead that was never
 * handed over.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('employee_id')->constrained('employees')->nullOnDelete();
        });

        DB::table('leads')->update(['created_by' => DB::raw('employee_id')]);
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
        });
    }
};
