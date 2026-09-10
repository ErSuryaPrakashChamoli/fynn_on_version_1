<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('training_enrollment_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('training_course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trainee_id')->constrained('users')->cascadeOnDelete();
            $table->string('certificate_number')->unique();
            $table->unsignedTinyInteger('final_score')->default(0);
            $table->date('issued_on');
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('file_path')->nullable();
            $table->timestamps();

            $table->index(['trainee_id', 'training_course_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_certificates');
    }
};
