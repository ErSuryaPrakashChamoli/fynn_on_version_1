<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_module_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->longText('content')->nullable();
            $table->string('content_type')->default('text');
            $table->string('video_url')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_required')->default(true);
            $table->string('status')->default('published');
            $table->timestamps();

            $table->index(['training_module_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_lessons');
    }
};
