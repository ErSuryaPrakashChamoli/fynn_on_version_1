<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('training_module_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('agenda')->nullable();
            $table->dateTime('scheduled_at');
            $table->unsignedSmallInteger('duration_minutes')->default(60);
            $table->string('mode')->default('classroom');
            $table->string('location')->nullable();
            $table->string('status')->default('scheduled');
            $table->timestamps();

            $table->index(['training_batch_id', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_sessions');
    }
};
