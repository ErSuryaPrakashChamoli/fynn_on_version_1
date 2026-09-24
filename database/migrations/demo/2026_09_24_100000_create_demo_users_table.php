<?php

use App\Support\Demo\DemoDatabase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Demo database only (database/migrations/demo) — run through
 * `php artisan demo:migrate`, never the plain `migrate`.
 *
 * The sandbox's own logins. Kept apart from the main users table so a
 * demo account can never authenticate against /admin, and so seeding or
 * resetting the demo never writes a row into the main database.
 */
return new class extends Migration
{
    /**
     * Pinned to the demo connection, so even a run without
     * --database=demo creates this table in the demo database.
     */
    public function getConnection(): ?string
    {
        return DemoDatabase::connectionName();
    }

    public function up(): void
    {
        Schema::create('demo_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_users');
    }
};
