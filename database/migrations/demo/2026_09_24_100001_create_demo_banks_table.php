<?php

use App\Support\Demo\DemoDatabase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Demo database only (database/migrations/demo) — run through
 * `php artisan demo:migrate`, never the plain `migrate`.
 *
 * The sandbox's tables live in their own database, so "a demo user must
 * never reach production data" is a property of the connection rather
 * than of any query: there is no main-database table on it to name.
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
        Schema::create('demo_banks', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('short_name')->nullable();
            $table->string('type')->default('private');
            $table->decimal('min_interest_rate', 5, 2)->default(0);
            $table->decimal('max_interest_rate', 5, 2)->default(0);
            $table->unsignedInteger('min_salary')->default(0);
            $table->decimal('payout_rate', 5, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_banks');
    }
};
