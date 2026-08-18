<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hierarchy_transfer_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('source_cluster_manager_id')
                ->nullable()
                ->constrained('employees')
                ->nullOnDelete();

            $table->foreignId('target_cluster_manager_id')
                ->nullable()
                ->constrained('employees')
                ->nullOnDelete();

            $table->string('transfer_type', 30);

            $table->json('selected_employee_ids')->nullable();

            $table->json('affected_employee_ids');

            $table->unsignedInteger('affected_count')
                ->default(0);

            $table->date('effective_date');

            $table->foreignId('performed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->text('remarks')->nullable();

            $table->timestamps();

            /*
             * Explicit short index names are required because
             * MySQL has a 64-character identifier limit.
             */
            $table->index(
                ['source_cluster_manager_id', 'created_at'],
                'htl_source_created_idx'
            );

            $table->index(
                ['target_cluster_manager_id', 'created_at'],
                'htl_target_created_idx'
            );

            $table->index(
                ['transfer_type', 'effective_date'],
                'htl_type_date_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hierarchy_transfer_logs');
    }
};
