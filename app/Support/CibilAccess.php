<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Who may use the CIBIL module. The Admin always may and is the only one
 * who decides for everybody else, from the CIBIL Access page: the
 * cibil.fetch permission is granted to whole roles and/or to individual
 * logins (Spatie direct permissions), and a login has access when either
 * route gives it. Taking access away also hides the reports that login
 * fetched earlier — the Admin still sees every report.
 */
final class CibilAccess
{
    public const string PERMISSION = 'cibil.fetch';

    public const string ADMIN_ROLE = 'Admin';

    public static function canUse(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $user->hasRole(self::ADMIN_ROLE) || $user->checkPermissionTo(self::PERMISSION);
    }

    public static function canManage(?User $user): bool
    {
        return (bool) $user?->hasRole(self::ADMIN_ROLE);
    }

    /**
     * @return list<string>
     */
    public static function grantedRoleNames(): array
    {
        return self::permission()->roles()->orderBy('name')->pluck('name')->all();
    }

    /**
     * Logins holding the permission directly, not through a role.
     *
     * @return list<int>
     */
    public static function grantedUserIds(): array
    {
        return self::permission()->users()->pluck('users.id')->map(fn (mixed $id): int => (int) $id)->all();
    }

    /**
     * Replaces the whole grant in one go with what the Admin picked.
     *
     * @param  array<int, string>  $roleNames
     * @param  array<int, int|string>  $userIds
     */
    public static function sync(array $roleNames, array $userIds): void
    {
        $permission = self::permission();

        $roleIds = Role::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $roleNames)
            ->where('name', '!=', self::ADMIN_ROLE)
            ->pluck('id');

        $permission->roles()->sync($roleIds);
        $permission->users()->sync(
            User::query()->whereKey(array_map('intval', $userIds))->pluck('id'),
        );

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Every login that can open the module right now: Admins, holders of a
     * granted role, and individually granted logins.
     *
     * @return Builder<User>
     */
    public static function usersWithAccess(): Builder
    {
        return User::query()->where(fn (Builder $query): Builder => $query
            ->whereHas('roles', fn (Builder $roles): Builder => $roles->where('name', self::ADMIN_ROLE))
            ->orWhereHas('roles', fn (Builder $roles): Builder => $roles->whereHas(
                'permissions',
                fn (Builder $permissions): Builder => $permissions->where('name', self::PERMISSION),
            ))
            ->orWhereHas('permissions', fn (Builder $permissions): Builder => $permissions->where('name', self::PERMISSION)));
    }

    /**
     * The migration creates it; findOrCreate keeps a fresh test database
     * (or a forgotten migration) from breaking the access page.
     */
    public static function permission(): Permission
    {
        /** @var Permission */
        return Permission::findOrCreate(self::PERMISSION, 'web');
    }
}
