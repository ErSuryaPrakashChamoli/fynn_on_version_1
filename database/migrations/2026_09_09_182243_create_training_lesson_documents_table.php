<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Downloadable lesson material.
 *
 * disk defaults to 'local' (private) rather than the app's 'public'
 * default, so a training PDF is never reachable at a guessable
 * /storage/... URL. Every download goes through the authorising
 * controller instead — see App\Http\Controllers\Academy\
 * TrainingDocumentDownloadController.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_lesson_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_lesson_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->boolean('is_downloadable')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_lesson_documents');
    }
};
