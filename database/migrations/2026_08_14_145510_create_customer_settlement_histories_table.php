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
        Schema::create('customer_settlement_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_settlement_id')
                ->constrained('customer_settlements')
                ->cascadeOnDelete();

            $table->foreignId('customer_id')
                ->constrained('customers')
                ->cascadeOnDelete();

            /*
             * What changed?
             *
             * Examples:
             * sales_snapshot_created
             * mis_imported
             * settlement_verified
             * settlement_rejected
             * settlement_revised
             * payment_updated
             */
            $table->string('action', 100);

            /*
             * Field that was changed, if applicable.
             */
            $table->string('field_name')->nullable();

            /*
             * Previous and new values.
             */
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();

            /*
             * Where did the value come from?
             *
             * sales
             * bank_mis
             * finance
             * system
             */
            $table->string('source', 50)->nullable();

            /*
             * Reason entered by Finance/Admin.
             */
            $table->text('reason')->nullable();

            /*
             * User who performed the action.
             */
            $table->foreignId('performed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            /*
             * Optional MIS batch reference.
             */
            $table->foreignId('mis_batch_id')
                ->nullable()
                ->constrained('mis_batches')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(
                ['customer_settlement_id', 'created_at'],
                'settlement_history_settlement_created_index'
            );

            $table->index(
                ['customer_id', 'created_at'],
                'settlement_history_customer_created_index'
            );

            $table->index(
                ['action'],
                'settlement_history_action_index'
            );
            // $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_settlement_histories');
    }
};
