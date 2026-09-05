<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plain old/new value log for every daily commitment change — a revised
 * commitment or a refreshed achievement. Intentionally not an audit
 * architecture: append-only rows, no updates, no deletes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_commitment_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_commitment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();

            $table->string('old_stage', 30)->nullable();
            $table->string('new_stage', 30)->nullable();
            $table->decimal('old_amount', 15, 2)->nullable();
            $table->decimal('new_amount', 15, 2)->nullable();
            $table->unsignedInteger('old_count')->nullable();
            $table->unsignedInteger('new_count')->nullable();
            $table->string('change_type', 20)->default('commitment');
            $table->text('note')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['daily_commitment_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_commitment_logs');
    }
};
