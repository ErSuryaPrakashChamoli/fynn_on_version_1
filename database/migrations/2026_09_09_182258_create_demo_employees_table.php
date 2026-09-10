<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('emp_code');
            $table->string('name');
            $table->string('email');
            $table->string('mobile_no', 20)->nullable();
            $table->string('designation');
            $table->string('department')->nullable();
            $table->string('city')->nullable();
            $table->foreignId('reports_to')->nullable()->constrained('demo_employees')->nullOnDelete();
            $table->date('joined_on')->nullable();
            $table->unsignedBigInteger('monthly_target')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'designation']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_employees');
    }
};
