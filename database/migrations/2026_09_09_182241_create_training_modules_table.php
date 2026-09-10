<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_course_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('status')->default('published');
            $table->timestamps();

            $table->index(['training_course_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_modules');
    }
};
