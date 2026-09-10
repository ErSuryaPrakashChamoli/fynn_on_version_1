<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The demo sandbox gets its OWN tables rather than a tenant_id column on
 * the production leads/customers/employees tables.
 *
 * The brief's requirement is "a demo user must never reach production
 * data". Tenant-scoping the live tables would make that a property of
 * every query — one forgotten global scope, one raw join, one export
 * away from leaking. Separate tables make it a property of the schema:
 * the Demo panel's resources are bound to models that physically cannot
 * name a production row, so there is no query to get wrong. It also
 * leaves the existing LMS schema completely unmodified, and makes
 * DemoResetService a truncate-and-reseed rather than a filtered delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_banks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('short_name')->nullable();
            $table->string('type')->default('private');
            $table->decimal('min_interest_rate', 5, 2)->default(0);
            $table->decimal('max_interest_rate', 5, 2)->default(0);
            $table->unsignedInteger('min_salary')->default(0);
            $table->decimal('payout_rate', 5, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_banks');
    }
};
