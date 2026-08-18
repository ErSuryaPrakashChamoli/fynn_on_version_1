<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('employees')
            ->orderBy('id')
            ->chunkById(500, function ($employees): void {
                foreach ($employees as $employee) {
                    $exists = DB::table('employee_reporting_history')
                        ->where('employee_id', $employee->id)
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    $effectiveDate = $employee->reporting_date
                        ?: $employee->doj
                        ?: ($employee->created_at
                            ? substr((string) $employee->created_at, 0, 10)
                            : now()->toDateString());

                    DB::table('employee_reporting_history')->insert([
                        'employee_id' => $employee->id,
                        'old_superviser_id' => null,
                        'old_manager_id' => null,
                        'old_cluster_id' => null,
                        'new_superviser_id' => $employee->superviser_id,
                        'new_manager_id' => $employee->manager_id,
                        'new_cluster_id' => $employee->cluster_id,
                        'effective_date' => $effectiveDate,
                        'effective_to' => $employee->exit_status === 'yes'
                            ? ($employee->exit_date ?: $effectiveDate)
                            : null,
                        'change_type' => 'joining',
                        'updated_by' => null,
                        'remarks' => 'Backfilled initial employee lifecycle history.',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    if ($employee->exit_status === 'yes' && $employee->exit_date) {
                        DB::table('employee_reporting_history')->insert([
                            'employee_id' => $employee->id,
                            'old_superviser_id' => $employee->superviser_id,
                            'old_manager_id' => $employee->manager_id,
                            'old_cluster_id' => $employee->cluster_id,
                            'new_superviser_id' => null,
                            'new_manager_id' => null,
                            'new_cluster_id' => null,
                            'effective_date' => $employee->exit_date,
                            'effective_to' => null,
                            'change_type' => 'exit',
                            'updated_by' => null,
                            'remarks' => 'Backfilled employee exit history.',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        DB::table('employee_reporting_history')
            ->whereIn('remarks', [
                'Backfilled initial employee lifecycle history.',
                'Backfilled employee exit history.',
            ])
            ->delete();
    }
};
