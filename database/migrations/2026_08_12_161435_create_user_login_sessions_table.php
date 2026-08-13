<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_login_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('employee_id')
                ->nullable()
                ->constrained('employees')
                ->nullOnDelete();

            /*
             * Laravel session ID.
             * Used to identify the browser login session
             * for heartbeat/logout tracking.
             */
            $table->string('session_id', 255)
                ->nullable()
                ->index();

            /*
             * Login/logout information.
             */
            $table->timestamp('login_at');
            $table->timestamp('logout_at')->nullable();

            /*
             * Last time browser communicated with server.
             */
            $table->timestamp('last_seen_at')->nullable();

            /*
             * Actual active screen time in seconds.
             */
            $table->unsignedInteger('screen_time_seconds')
                ->default(0);

            /*
             * Device information.
             */
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            /*
             * Examples:
             * logout
             * browser_closed
             * timeout
             * session_expired
             */
            $table->string('logout_reason', 50)->nullable();

            $table->timestamps();

            /*
             * Reporting indexes.
             */
            $table->index([
                'employee_id',
                'login_at',
            ]);

            $table->index([
                'user_id',
                'login_at',
            ]);

            $table->index([
                'last_seen_at',
            ]);
            // $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_login_sessions');
    }
};
