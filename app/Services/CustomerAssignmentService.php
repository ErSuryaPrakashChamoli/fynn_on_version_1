<?php

namespace App\Services;

use App\Models\CustomerAssignment;
use App\Models\CustomerAssignmentBatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Hands customers or AI customer records to an employee in one batch.
 *
 * Shared by the "Assign to User" bulk action and the "Assign by S. No."
 * range action so both skip rows that already belong to somebody and
 * record the hand-over the same way.
 */
class CustomerAssignmentService
{
    public const TARGET_CUSTOMER = 'customer';

    public const TARGET_AI_RECORD = 'ai_customer_record';

    /**
     * @param  Collection<int, int>  $targetIds  Customer ids or ai_customer_record ids, per $targetType.
     * @return array{assigned: int, skipped: int, batch: ?CustomerAssignmentBatch}
     */
    public function assign(Collection $targetIds, int $employeeId, string $targetType, ?int $assignedBy): array
    {
        $column = self::columnFor($targetType);
        $targetIds = $targetIds->filter()->unique()->values();

        if ($targetIds->isEmpty()) {
            return ['assigned' => 0, 'skipped' => 0, 'batch' => null];
        }

        return DB::transaction(function () use ($targetIds, $employeeId, $column, $assignedBy): array {
            $alreadyAssignedIds = CustomerAssignment::query()->whereIn($column, $targetIds)->pluck($column);
            $toAssign = $targetIds->diff($alreadyAssignedIds)->values();

            if ($toAssign->isEmpty()) {
                return ['assigned' => 0, 'skipped' => $targetIds->count(), 'batch' => null];
            }

            $batch = CustomerAssignmentBatch::create([
                'assigned_by' => $assignedBy,
                'employee_id' => $employeeId,
                'customer_count' => $toAssign->count(),
            ]);

            foreach ($toAssign as $id) {
                CustomerAssignment::create([
                    'batch_id' => $batch->id,
                    $column => $id,
                    'employee_id' => $employeeId,
                    'assigned_by' => $assignedBy,
                ]);
            }

            return [
                'assigned' => $toAssign->count(),
                'skipped' => $targetIds->count() - $toAssign->count(),
                'batch' => $batch,
            ];
        });
    }

    public static function columnFor(string $targetType): string
    {
        return $targetType === self::TARGET_AI_RECORD ? 'ai_customer_record_id' : 'customer_id';
    }
}
