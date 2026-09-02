<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * customer_journey_audits.access_type was created as a fixed MySQL ENUM
 * that predates the Admin Organisation-Wide Handover access type — every
 * new access type would otherwise require another schema migration.
 * Converts it to a plain string, validated in app code via the
 * JourneyAccessType enum cast instead (same convention already used for
 * hierarchy_transfer_logs.transfer_type). Drop+re-add rather than
 * ->change() since this app has no doctrine/dbal dependency installed and
 * the test suite runs on SQLite, which ->change() cannot target without it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_journey_audits', function (Blueprint $table) {
            $table->dropIndex('cja_access_performed_idx');
            $table->dropColumn('access_type');
        });

        Schema::table('customer_journey_audits', function (Blueprint $table) {
            $table->string('access_type', 40)->after('action');
        });

        Schema::table('customer_journey_audits', function (Blueprint $table) {
            $table->index(['access_type', 'performed_at'], 'cja_access_performed_idx');
        });
    }

    public function down(): void
    {
        Schema::table('customer_journey_audits', function (Blueprint $table) {
            $table->dropIndex('cja_access_performed_idx');
            $table->dropColumn('access_type');
        });

        Schema::table('customer_journey_audits', function (Blueprint $table) {
            $table->enum('access_type', [
                'normal',
                'temporary_delegation',
                'emergency_takeover',
                'permanent_reassignment',
                'escalation',
            ])->after('action');
        });

        Schema::table('customer_journey_audits', function (Blueprint $table) {
            $table->index(['access_type', 'performed_at'], 'cja_access_performed_idx');
        });
    }
};
