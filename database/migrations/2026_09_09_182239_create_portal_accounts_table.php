<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pins a user to exactly one non-admin portal.
 *
 * Deliberately a side table rather than columns on `users`: the existing
 * LMS user rows are left byte-for-byte untouched, and "has no portal
 * account" — true for every pre-existing user — keeps meaning "ordinary
 * LMS user, behaves exactly as before". The presence of a row is the
 * ONLY thing that narrows a user, so there is no way to accidentally
 * widen an admin by writing here.
 *
 * user_id is unique: one account, one portal, no dual identities.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('portal');
            $table->string('portal_role');
            $table->boolean('is_active')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['portal', 'portal_role']);
            $table->index(['tenant_id', 'portal_role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_accounts');
    }
};
