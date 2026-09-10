<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records the last GENUINE user interaction on a login session.
 *
 * Deliberately separate from last_seen_at, which the heartbeat only
 * advances while the browser tab is visible and which therefore keeps
 * moving for a tab left open on an unattended screen. Idle logout has to
 * mean "nobody has touched the keyboard or mouse", so it needs its own
 * column rather than a reinterpretation of the screen-time one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_login_sessions', function (Blueprint $table) {
            $table->timestamp('last_activity_at')
                ->nullable()
                ->after('last_seen_at');

            // The idle sweeper scans open sessions by this column.
            $table->index(['logout_at', 'last_activity_at'], 'user_login_sessions_idle_index');
        });

        /*
         * Existing open rows have never recorded an interaction. Seeding
         * them from last_seen_at (falling back to login_at) stops the
         * sweeper closing every historic session the moment it first
         * runs, which would otherwise rewrite the login log.
         */
        Schema::getConnection()
            ->table('user_login_sessions')
            ->whereNull('last_activity_at')
            ->update([
                'last_activity_at' => Schema::getConnection()->raw('COALESCE(last_seen_at, login_at)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('user_login_sessions', function (Blueprint $table) {
            $table->dropIndex('user_login_sessions_idle_index');
            $table->dropColumn('last_activity_at');
        });
    }
};
