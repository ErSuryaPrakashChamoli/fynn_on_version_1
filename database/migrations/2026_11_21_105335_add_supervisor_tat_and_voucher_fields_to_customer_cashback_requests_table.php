<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * - A Team Leader's cashback form waits for the supervisor of whoever
 *   filled it, with a 24-hour turnaround: past supervisor_due_at it moves
 *   to the Admin on its own (escalated_at).
 * - The voucher names the file's Manager and Cluster Manager (the paper
 *   form's single "Floor Manager" row is split in two).
 * - "Paid By" is typed by the Admin / Accounts when recording the payment
 *   (the person who actually paid, who need not have a login).
 * - A paid cashback that has to be paid again (a second payment, so a
 *   second UTR) is revised: the revision is a new form on the same case,
 *   linked to the original (revision_of_id / revision_no), approved
 *   through the same process and paid with its own UTR.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_cashback_requests', function (Blueprint $table): void {
            $table->foreignId('cluster_manager_employee_id')->nullable()->after('manager_employee_id')->constrained('employees')->nullOnDelete();
            $table->foreignId('supervisor_employee_id')->nullable()->after('cluster_manager_employee_id')->constrained('employees')->nullOnDelete();
            $table->dateTime('supervisor_due_at')->nullable()->after('supervisor_employee_id')->index();
            $table->dateTime('escalated_at')->nullable()->after('supervisor_due_at');
            $table->string('paid_by_name')->nullable()->after('paid_by');
            $table->foreignId('revision_of_id')->nullable()->after('customer_id')->constrained('customer_cashback_requests')->nullOnDelete();
            $table->unsignedSmallInteger('revision_no')->nullable()->after('revision_of_id');
        });

        DB::table('customer_cashback_requests')
            ->orderBy('id')
            ->each(function (object $row): void {
                $owner = $row->aro_employee_id ? DB::table('employees')->find($row->aro_employee_id) : null;
                $update = [
                    'cluster_manager_employee_id' => $owner->cluster_id ?? null,
                ];

                // Forms already waiting: the file's Manager stands as the
                // supervisor, and the 24 hours run from when it was filled.
                if ($row->status === 'pending_manager') {
                    $update['supervisor_employee_id'] = $row->manager_employee_id;
                    $update['supervisor_due_at'] = date('Y-m-d H:i:s', strtotime((string) $row->created_at) + 24 * 3600);
                }

                DB::table('customer_cashback_requests')->where('id', $row->id)->update($update);
            });
    }

    public function down(): void
    {
        Schema::table('customer_cashback_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('revision_of_id');
            $table->dropColumn('revision_no');
            $table->dropConstrainedForeignId('supervisor_employee_id');
            $table->dropConstrainedForeignId('cluster_manager_employee_id');
            $table->dropColumn(['supervisor_due_at', 'escalated_at', 'paid_by_name']);
        });
    }
};
