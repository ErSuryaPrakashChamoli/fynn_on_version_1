<?php

use Database\Seeders\VotingSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Voting & Feedback submodule (Setting → Voting): Admin-defined poll
 * types (the answer dropdown), the polls themselves, who each poll went to
 * and whether they voted, and the votes (with no user when anonymous).
 * Default poll types are seeded so the module works straight away.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('poll_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('description')->nullable();
            $table->json('options');
            $table->boolean('allow_comment')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('polls', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->text('question');
            $table->foreignId('poll_type_id')->constrained('poll_types')->restrictOnDelete();
            $table->json('options');
            $table->boolean('allow_comment')->default(true);
            $table->boolean('is_mandatory')->default(false);
            $table->boolean('is_anonymous')->default(false);
            $table->string('audience')->default('company');
            $table->json('audience_roles')->nullable();
            $table->json('audience_designations')->nullable();
            $table->boolean('is_active')->default(true);
            $table->dateTime('expires_at')->nullable();
            $table->unsignedInteger('recipients_count')->default(0);
            $table->unsignedInteger('votes_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('poll_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('poll_id')->constrained('polls')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->dateTime('voted_at')->nullable();
            $table->timestamps();

            $table->unique(['poll_id', 'user_id']);
            $table->index(['user_id', 'voted_at']);
        });

        Schema::create('poll_votes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('poll_id')->constrained('polls')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('option');
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->index(['poll_id', 'option']);
        });

        (new VotingSeeder)->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('poll_votes');
        Schema::dropIfExists('poll_recipients');
        Schema::dropIfExists('polls');
        Schema::dropIfExists('poll_types');
    }
};
