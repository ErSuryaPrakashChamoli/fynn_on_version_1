<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A duplicate PAN can now clash with an open lead as well as a customer,
     * and the approval can be asked for while adding a lead, not only a
     * customer. customer_id is therefore optional (matched_lead_id carries a
     * lead clash), bank and loan type are only asked on customer requests,
     * and used_at marks an approval that has been spent.
     */
    public function up(): void
    {
        Schema::table('customer_pan_requests', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->change();
            $table->foreignId('requested_bank_id')->nullable()->change();
            $table->string('requested_bank_name')->nullable()->change();
            $table->string('requested_loan_type')->nullable()->change();

            $table->foreignId('matched_lead_id')
                ->nullable()
                ->after('lead_id')
                ->constrained('leads')
                ->nullOnDelete();

            $table->string('request_for', 20)
                ->default('customer')
                ->after('matched_lead_id');

            $table->timestamp('used_at')->nullable()->after('application_id');

            $table->index(['requested_by', 'pan_number', 'request_for'], 'pan_requests_requester_pan_for_index');
        });
    }

    public function down(): void
    {
        Schema::table('customer_pan_requests', function (Blueprint $table) {
            $table->dropIndex('pan_requests_requester_pan_for_index');
            $table->dropConstrainedForeignId('matched_lead_id');
            $table->dropColumn(['request_for', 'used_at']);
        });
    }
};
