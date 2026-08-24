<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('performance_metric_ratios', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('numerator_key');
            $table->string('denominator_key');
            $table->string('format')->default('percentage');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();
        });

        $now = now();

        DB::table('performance_metric_ratios')->insert([
            [
                'name' => 'Login → Approval Ratio',
                'numerator_key' => 'approval_count',
                'denominator_key' => 'login_count',
                'format' => 'percentage',
                'is_active' => true,
                'sort_order' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Approval → Disbursal Ratio',
                'numerator_key' => 'disbursal_count',
                'denominator_key' => 'approval_count',
                'format' => 'percentage',
                'is_active' => true,
                'sort_order' => 2,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Drop Ratio',
                'numerator_key' => 'dropped_count',
                'denominator_key' => 'login_count',
                'format' => 'percentage',
                'is_active' => true,
                'sort_order' => 3,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Login → Disbursal Conversion',
                'numerator_key' => 'disbursal_count',
                'denominator_key' => 'login_count',
                'format' => 'percentage',
                'is_active' => true,
                'sort_order' => 4,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('performance_metric_ratios');
    }
};
