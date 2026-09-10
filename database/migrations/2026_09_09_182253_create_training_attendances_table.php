<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('training_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('training_session_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('trainee_id')->constrained('users')->cascadeOnDelete();
            $table->date('attendance_date');
            $table->string('status')->default('present');
            $table->string('remarks')->nullable();
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['training_batch_id', 'trainee_id', 'attendance_date'],
                'training_attendance_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_attendances');
    }
};
