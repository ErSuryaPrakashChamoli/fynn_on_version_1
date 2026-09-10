<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The multi-tenant root the Academy and Demo portals hang off.
 *
 * No existing LMS table gains a tenant_id — production continues to run
 * exactly as it does today, and is simply represented here by a row so a
 * later SaaS split has something to migrate onto. Only the training_* and
 * demo_* tables introduced alongside this one are tenant-scoped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('type')->default('client');
            $table->boolean('is_demo')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('brand_name')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->index(['type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
