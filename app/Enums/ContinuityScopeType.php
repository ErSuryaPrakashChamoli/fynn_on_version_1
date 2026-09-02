<?php

namespace App\Enums;

/**
 * How wide a Team Continuity rule reaches below the original employee.
 * "Individual" covers only records directly owned by the original employee
 * themselves; "HierarchyBranch" covers the original employee's whole
 * subordinate tree (see HierarchyHelper::subordinateIds()) — e.g. a
 * Manager's branch includes their Team Leaders and Callers.
 */
enum ContinuityScopeType: string
{
    case Individual = 'individual';
    case HierarchyBranch = 'hierarchy_branch';

    public function label(): string
    {
        return match ($this) {
            self::Individual => "Original Employee's Own Records Only",
            self::HierarchyBranch => "Original Employee's Full Hierarchy Branch",
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $type): array => [$type->value => $type->label()])
            ->all();
    }
}
