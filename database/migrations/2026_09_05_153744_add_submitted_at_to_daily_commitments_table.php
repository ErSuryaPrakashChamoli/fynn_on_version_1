<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks the moment the employee pressed "Submit Final Status". Until then
 * the day is still open and the result stays IN PROGRESS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_commitments', function (Blueprint $table) {
            $table->timestamp('submitted_at')->nullable()->after('result');
        });
    }

    public function down(): void
    {
        Schema::table('daily_commitments', function (Blueprint $table) {
            $table->dropColumn('submitted_at');
        });
    }
};
