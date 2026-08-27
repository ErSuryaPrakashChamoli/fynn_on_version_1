<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generalizes customer_journey_delegations from "Manager delegates to
 * Manager" into the Team Continuity / Backup Access submodule: any
 * hierarchy level, a coverage type (existing/new/existing+new cases), a
 * scope (the original employee's own records vs their whole branch), an
 * access type distinguishing planned delegation / emergency takeover /
 * admin organisation-wide handover, and an explicit admin-override flag
 * that is hard-validated server-side (see CustomerJourneyDelegationService).
 * Column names (delegating_manager_id/acting_manager_id) are left as-is —
 * renaming would touch every existing reference for no functional gain —
 * but they now represent "original employee"/"backup employee" at any
 * designation, not Managers specifically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_journey_delegations', function (Blueprint $table) {
            $table->string('coverage_type', 20)->default('existing_and_new')->after('modules');
            $table->string('scope_type', 20)->default('hierarchy_branch')->after('coverage_type');
            $table->string('access_type', 30)->default('temporary_delegation')->after('scope_type');
            $table->boolean('is_admin_override')->default(false)->after('access_type');
        });

        Schema::table('customer_journey_audits', function (Blueprint $table) {
            $table->string('case_type', 20)->nullable()->after('access_type');
            $table->boolean('is_admin_override')->default(false)->after('case_type');
            $table->json('original_hierarchy')->nullable()->after('is_admin_override');
            $table->json('backup_hierarchy')->nullable()->after('original_hierarchy');
        });
    }

    public function down(): void
    {
        Schema::table('customer_journey_delegations', function (Blueprint $table) {
            $table->dropColumn(['coverage_type', 'scope_type', 'access_type', 'is_admin_override']);
        });

        Schema::table('customer_journey_audits', function (Blueprint $table) {
            $table->dropColumn(['case_type', 'is_admin_override', 'original_hierarchy', 'backup_hierarchy']);
        });
    }
};
