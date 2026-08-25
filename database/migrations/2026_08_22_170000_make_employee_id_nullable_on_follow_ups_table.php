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
        Schema::table('follow_ups', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE follow_ups MODIFY employee_id BIGINT UNSIGNED NULL');
        }

        Schema::table('follow_ups', function (Blueprint $table) {
            $table->foreign('employee_id')->references('id')->on('employees')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('follow_ups', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE follow_ups MODIFY employee_id BIGINT UNSIGNED NOT NULL');
        }

        Schema::table('follow_ups', function (Blueprint $table) {
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
        });
    }
};
