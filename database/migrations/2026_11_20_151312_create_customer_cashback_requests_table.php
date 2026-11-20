<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The digital cashback form (the paper "Acknowledgment" voucher). A Team
 * Leader or Manager fills it on a customer file declared for cashback or
 * expected cashback; it goes Manager → Admin for approval, and once
 * approved the Admin / Accounts record the payment against it. The
 * customer's own cashback figure (customers.cashback, which the
 * achievement and incentive maths already read) follows the live form.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('cashback_option')->nullable()->after('cashback');
        });

        Schema::create('customer_cashback_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('voucher_no')->nullable()->unique();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('type');
            $table->decimal('amount', 12, 2);
            $table->decimal('approved_amount', 12, 2)->nullable();
            $table->string('status')->index();
            $table->text('remarks')->nullable();

            // How the customer wants to be paid (filled with the form,
            // correctable by the Admin / Accounts when paying).
            $table->string('payment_mode')->nullable();
            $table->string('upi_platform')->nullable();
            $table->string('payee_upi')->nullable();
            $table->string('payee_account_no')->nullable();
            $table->string('payee_ifsc', 20)->nullable();

            // Who sat above the file's owner when the form was filled.
            $table->foreignId('aro_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('team_leader_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('manager_employee_id')->nullable()->constrained('employees')->nullOnDelete();

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('manager_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('manager_approved_at')->nullable();
            $table->text('manager_note')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->text('approval_note')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('retrieved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('retrieved_at')->nullable();
            $table->text('retrieval_reason')->nullable();

            // Payment, recorded by the Admin / Accounts once approved.
            $table->string('payment_status')->default('not_paid')->index();
            $table->string('transaction_id')->nullable();
            $table->date('payment_date')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('payment_remarks')->nullable();
            $table->foreignId('payment_updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('payment_updated_at')->nullable();

            $table->timestamps();

            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_cashback_requests');

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn('cashback_option');
        });
    }
};
