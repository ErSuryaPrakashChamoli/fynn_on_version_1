<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `follow_up_date` recorded nothing that `created_at` did not already hold —
 * a follow-up is always logged on the day it happens, so the column was a
 * duplicate of the row's creation date and is no longer written or read
 * anywhere in the application.
 *
 * The columns are kept (not dropped) so the 100-odd historically backdated
 * `leads.follow_up_date` values survive; they are only relaxed to nullable so
 * new rows can be inserted without them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('follow_ups', function (Blueprint $table) {
            $table->date('follow_up_date')->nullable()->change();
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->date('follow_up_date')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('follow_ups', function (Blueprint $table) {
            $table->date('follow_up_date')->nullable(false)->change();
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->date('follow_up_date')->nullable(false)->change();
        });
    }
};
