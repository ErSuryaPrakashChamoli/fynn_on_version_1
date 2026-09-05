<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expected OTP for one caller on one day, entered by their TL/Manager/Admin.
 * Only the expectation is stored — the actual OTP count is always read live
 * from `customers` (the LMS's existing otp_count definition), never copied.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_caller_otps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('expected_otp')->default(0);
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_caller_otps');
    }
};
