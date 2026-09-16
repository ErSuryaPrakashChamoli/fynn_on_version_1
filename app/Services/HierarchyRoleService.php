<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Keeps a login's hierarchy role in step with its employee's designation.
 *
 * Roles open screens; the reporting tree decides what data those screens
 * show. The two must not drift apart: a promoted or demoted employee would
 * keep the menus of the level they left, and a Business Head role on
 * somebody who is not one would open Business Head screens to them.
 */
class HierarchyRoleService
{
    public const BUSINESS_HEAD_ROLE = 'Business Head';

    /**
     * The role that belongs to each hierarchy designation.
     *
     * @var array<int, string>
     */
    public const ROLES = [
        Employee::DESIGNATION_CALLER => 'Caller',
        Employee::DESIGNATION_TEAM_LEADER => 'Team Leader',
        Employee::DESIGNATION_MANAGER => 'Manager',
        Employee::DESIGNATION_CLUSTER => 'Cluster Manager',
        Employee::DESIGNATION_BUSINESS_HEAD => self::BUSINESS_HEAD_ROLE,
    ];

    /**
     * Swap the user's hierarchy role for the one matching $designation.
     *
     * Only hierarchy roles are touched — Admin, MIS, Accounts, IT and
     * Employee stay as they were chosen. A login holding no hierarchy role
     * is left that way, except that the Business Head seat always carries
     * its role and nobody else may keep it.
     */
    public function syncUserRole(User $user, ?int $designation): void
    {
        $target = $designation === null ? null : (self::ROLES[$designation] ?? null);
        $held = $user->getRoleNames()->intersect(self::ROLES)->values();

        if ($target === null) {
            if ($held->contains(self::BUSINESS_HEAD_ROLE)) {
                $user->removeRole(self::BUSINESS_HEAD_ROLE);
            }

            return;
        }

        if ($held->isEmpty() && $target !== self::BUSINESS_HEAD_ROLE) {
            return;
        }

        foreach ($held as $role) {
            if ($role !== $target) {
                $user->removeRole($role);
            }
        }

        if (! $user->hasRole($target)) {
            $user->assignRole(Role::findOrCreate($target));
        }
    }

    /**
     * Why a login for $employee cannot have the role $roleName, or null
     * when it can. The Business Head role belongs to Business Heads only,
     * and a Business Head's login carries it (or Admin).
     */
    public function roleProblem(?Employee $employee, ?string $roleName): ?string
    {
        $isBusinessHead = $employee?->designation === Employee::DESIGNATION_BUSINESS_HEAD;

        if ($roleName === self::BUSINESS_HEAD_ROLE && ! $isBusinessHead) {
            return "Only a Business Head's login can have the Business Head role.";
        }

        if ($isBusinessHead && $roleName !== null && ! in_array($roleName, [self::BUSINESS_HEAD_ROLE, 'Admin'], true)) {
            return "A Business Head's login must have the Business Head role.";
        }

        return null;
    }
}
