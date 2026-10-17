<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A CIBIL pull is now made for a lead (My Leads or an assigned lead) or a
 * customer file, so the report links to whichever it was. For a lead, the
 * manager then records the bureau decision (Eligible / Not Eligible) on the
 * report, which also moves the lead to that status. The bureau needs name,
 * PAN and date of birth; the mobile number became optional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cibil_reports', function (Blueprint $table) {
            $table->foreignId('lead_id')->nullable()->after('customer_id')->constrained('leads')->nullOnDelete();
            $table->foreignId('customer_assignment_id')->nullable()->after('lead_id')->constrained('customer_assignments')->nullOnDelete();

            $table->string('mobile_no', 15)->nullable()->change();

            $table->string('bureau_decision', 20)->nullable()->after('error_message');
            // Kept when the login is deleted: the decision still stands.
            $table->foreignId('decided_by')->nullable()->after('bureau_decision')->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable()->after('decided_by');
            $table->text('decision_remarks')->nullable()->after('decided_at');
        });
    }

    public function down(): void
    {
        Schema::table('cibil_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('decided_by');
            $table->dropColumn(['bureau_decision', 'decided_at', 'decision_remarks']);

            $table->dropConstrainedForeignId('customer_assignment_id');
            $table->dropConstrainedForeignId('lead_id');

            $table->string('mobile_no', 15)->nullable(false)->change();
        });
    }
};
