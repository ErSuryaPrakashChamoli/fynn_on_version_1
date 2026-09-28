<?php

use Database\Seeders\HelpDeskSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Help Desk: complaint categories (with their reasons and routing),
 * priorities (with the Admin-set resolution SLA), the tickets themselves
 * and the comment / event thread under each ticket.
 *
 * Default categories and priorities are seeded here so the module works
 * the moment the migration runs; the Admin edits them from the panel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('complaint_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('description')->nullable();
            $table->string('routing')->default('team');
            $table->json('handler_roles')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('complaint_reasons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->constrained('complaint_categories')->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('complaint_priorities', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('color')->default('gray');
            $table->unsignedInteger('resolve_within_minutes');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('complaints', function (Blueprint $table): void {
            $table->id();
            $table->string('ticket_no')->nullable()->unique();
            $table->foreignId('raised_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('complaint_categories')->restrictOnDelete();
            $table->foreignId('reason_id')->nullable()->constrained('complaint_reasons')->nullOnDelete();
            $table->foreignId('priority_id')->constrained('complaint_priorities')->restrictOnDelete();
            $table->string('subject');
            $table->text('description');
            $table->json('attachments')->nullable();
            $table->string('status')->default('open')->index();
            $table->string('handler_role')->nullable()->index();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('sla_started_at')->nullable();
            $table->dateTime('due_at')->nullable()->index();
            $table->unsignedTinyInteger('escalation_level')->default(0);
            $table->dateTime('escalated_at')->nullable();
            $table->foreignId('escalated_to')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('first_response_at')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_note')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('reopened_count')->default(0);
            $table->timestamps();
        });

        Schema::create('complaint_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('complaint_id')->constrained('complaints')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type')->default('comment');
            $table->text('body');
            $table->boolean('is_internal')->default(false);
            $table->timestamps();
        });

        (new HelpDeskSeeder)->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('complaint_comments');
        Schema::dropIfExists('complaints');
        Schema::dropIfExists('complaint_priorities');
        Schema::dropIfExists('complaint_reasons');
        Schema::dropIfExists('complaint_categories');
    }
};
