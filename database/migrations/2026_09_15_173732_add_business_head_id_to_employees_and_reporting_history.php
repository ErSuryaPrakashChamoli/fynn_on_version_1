<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the Business Head level above Cluster Managers.
 *
 * Every employee keeps one column per level above them
 * (superviser_id, manager_id, cluster_id and now business_head_id). A
 * level that somebody skips — a Team Leader reporting straight to a
 * Cluster Manager — is simply left null, so their direct boss is the
 * first filled column reading upward.
 *
 * Purely additive: no existing value is touched, so every current
 * target, achievement and visibility figure stays exactly as it was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('business_head_id')
                ->nullable()
                ->after('cluster_id')
                ->constrained('employees')
                ->nullOnDelete();
        });

        Schema::table('employee_reporting_history', function (Blueprint $table) {
            $table->foreignId('old_business_head_id')
                ->nullable()
                ->after('old_cluster_id')
                ->constrained('employees')
                ->nullOnDelete();

            $table->foreignId('new_business_head_id')
                ->nullable()
                ->after('new_cluster_id')
                ->constrained('employees')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employee_reporting_history', function (Blueprint $table) {
            $table->dropConstrainedForeignId('new_business_head_id');
            $table->dropConstrainedForeignId('old_business_head_id');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('business_head_id');
        });
    }
};
