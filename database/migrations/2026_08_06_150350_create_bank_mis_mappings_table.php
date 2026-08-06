<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bank_mis_mappings', function (Blueprint $table) {
            $table->id();
            // $table->foreignId('bank_id')
            //     ->constrained()
            //     ->cascadeOnDelete();

            $table->string('bank_name')->nullable();

            // Excel Header Name
            $table->string('excel_column');

            // Internal FYNN-ON Field
            $table->string('system_field');

            // Data Type
            $table->enum('data_type', [
                'string',
                'integer',
                'decimal',
                'date',
                'boolean',
            ])->default('string');

            // Is this column mandatory?
            $table->boolean('is_required')->default(false);

            // Used while matching customer
            $table->boolean('is_matching_field')->default(false);

            // Ignore this column while importing
            $table->boolean('is_active')->default(true);

            // Import Order (optional)
            $table->unsignedSmallInteger('sort_order')->default(1);

            $table->timestamps();

            $table->unique(['bank_name', 'excel_column']);

            $table->index('system_field');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_mis_mappings');
    }
};
