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
        Schema::create('mis_batches', function (Blueprint $table) {
            $table->id();
            /*
             * Human-readable batch number.
             *
             * Example:
             * MIS-20260812-0001
             */
            $table->string('batch_no')->unique();

            /*
             * Date represented by the MIS file.
             */
            $table->date('batch_date')->nullable();

            /*
             * Original uploaded filename.
             */
            $table->string('file_name')->nullable();

            /*
             * Storage path of the uploaded MIS file.
             */
            $table->string('file_path')->nullable();

            /*
             * How this batch entered the system.
             *
             * excel = Excel/CSV import
             * manual = manually created
             * api = future API integration
             */
            $table->string('source', 30)->default('excel');

            /*
             * Import lifecycle.
             */
            $table->enum('status', [
                'pending',
                'processing',
                'completed',
                'failed',
            ])->default('pending');

            /*
             * Import statistics.
             */
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('successful_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);
            $table->unsignedInteger('unmatched_rows')->default(0);

            /*
             * Optional import error information.
             */
            $table->json('error_summary')->nullable();

            /*
             * Who imported/created the batch.
             */
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            /*
             * Import completion timestamp.
             */
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['batch_date', 'status']);
            $table->index(['source', 'status']);
            // $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mis_batches');
    }
};
