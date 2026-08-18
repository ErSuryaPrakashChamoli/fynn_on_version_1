<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('account_verified')->default(false)->after('disbursal_finalized');
            $table->foreignId('account_verified_by')->nullable()->after('account_verified')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('account_verified_at')->nullable()->after('account_verified_by');
            $table->text('account_remark')->nullable()->after('account_verified_at');
            $table->boolean('incentive_calculated')->default(false)->after('account_remark');
        });

        Schema::table('customer_settlements', function (Blueprint $table) {
            $table->string('sales_loan_type')->nullable()->after('sales_disbursal_amount');
            $table->decimal('sales_rate', 5, 2)->nullable()->after('sales_loan_type');

            $table->string('mis_loan_type')->nullable()->after('mis_disbursal_amount');
            $table->decimal('mis_payment', 15, 2)->nullable()->after('actual_payable_amount');
            $table->decimal('payment_difference', 15, 2)->default(0)->after('mis_payment');

            $table->string('cancellation_status')->nullable()->after('mis_disbursal_date');
            $table->date('cancellation_date')->nullable()->after('cancellation_status');
            $table->decimal('cancellation_recovery', 15, 2)->default(0)->after('cancellation_date');
            $table->decimal('recovery_received', 15, 2)->default(0)->after('cancellation_recovery');
            $table->decimal('recovery_pending', 15, 2)->default(0)->after('recovery_received');

            $table->decimal('advance_received', 15, 2)->default(0)->after('recovery_pending');
            $table->decimal('advance_adjusted', 15, 2)->default(0)->after('advance_received');
            $table->decimal('advance_outstanding', 15, 2)->default(0)->after('advance_adjusted');

            $table->decimal('payment_received_amount', 15, 2)->default(0)->after('payment_received_date');
            $table->decimal('gross_payable_amount', 15, 2)->default(0)->after('payment_received_amount');
            $table->decimal('gst_rate', 5, 2)->default(18)->after('gross_payable_amount');
            $table->decimal('tds_rate', 5, 2)->default(2)->after('gst_rate');
            $table->decimal('gst_amount', 15, 2)->default(0)->after('tds_rate');
            $table->decimal('tds_amount', 15, 2)->default(0)->after('gst_amount');
            $table->decimal('net_payable_amount', 15, 2)->default(0)->after('tds_amount');
            $table->decimal('surplus_amount', 15, 2)->default(0)->after('net_payable_amount');
            $table->decimal('outstanding_amount', 15, 2)->default(0)->after('surplus_amount');
        });
    }

    public function down(): void
    {
        Schema::table('customer_settlements', function (Blueprint $table) {
            foreach ([
                'sales_loan_type', 'sales_rate', 'mis_loan_type', 'mis_payment', 'payment_difference',
                'cancellation_status', 'cancellation_date', 'cancellation_recovery', 'recovery_received',
                'recovery_pending', 'advance_received', 'advance_adjusted', 'advance_outstanding',
                'payment_received_amount', 'gross_payable_amount', 'gst_rate', 'tds_rate', 'gst_amount',
                'tds_amount', 'net_payable_amount', 'surplus_amount', 'outstanding_amount',
            ] as $column) {
                $table->dropColumn($column);
            }
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign(['account_verified_by']);
            $table->dropColumn([
                'account_verified', 'account_verified_by', 'account_verified_at',
                'account_remark', 'incentive_calculated',
            ]);
        });
    }
};
