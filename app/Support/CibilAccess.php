<?php

namespace App\Support;

use App\Enums\CibilRequestType;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Who may do what in the CIBIL module. The Admin always may do everything
 * and is the only one who decides for everybody else, from the CIBIL Access
 * page: each permission is granted to whole roles and/or to individual
 * logins (Spatie direct permissions), and a login holds it when either
 * route gives it.
 *
 *  - cibil.fetch (module access): open the module, see the CIBIL profile on
 *    the leads and customer files they can see, run score checks.
 *  - cibil.full_report: request full reports and read them.
 *  - cibil.pdf: request PDF reports and open them.
 *  - cibil.refresh: ask for a fresh pull over a reusable report, and send a
 *    new request after one whose outcome was uncertain.
 *  - cibil.audit: the CIBIL audit history and the provider's references.
 *
 * The finer permissions only count together with module access. Taking
 * module access away also hides the reports that login could see — the
 * Admin still sees every report. Managing the settings and this page stays
 * with the Admin.
 */
final class CibilAccess
{
    public const string PERMISSION = 'cibil.fetch';

    public const string FULL_REPORT_PERMISSION = 'cibil.full_report';

    public const string PDF_PERMISSION = 'cibil.pdf';

    public const string REFRESH_PERMISSION = 'cibil.refresh';

    public const string AUDIT_PERMISSION = 'cibil.audit';

    /** @var list<string> */
    public const array EXTRA_PERMISSIONS = [
        self::FULL_REPORT_PERMISSION,
        self::PDF_PERMISSION,
        self::REFRESH_PERMISSION,
        self::AUDIT_PERMISSION,
    ];

    public const string ADMIN_ROLE = 'Admin';

    /**
     * What each finer permission allows, for the CIBIL Access page.
     *
     * @var array<string, array{label: string, description: string}>
     */
    public const array EXTRA_PERMISSION_LABELS = [
        self::FULL_REPORT_PERMISSION => [
            'label' => 'Full credit reports',
            'description' => 'Request the full CIBIL report and read it (accounts, enquiries, addresses).',
        ],
        self::PDF_PERMISSION => [
            'label' => 'PDF reports',
            'description' => 'Request the CIBIL report as a PDF and open it.',
        ],
        self::REFRESH_PERMISSION => [
            'label' => 'Fresh pulls',
            'description' => 'Ask the bureau again although a reusable report exists, and retry after a request whose outcome was uncertain. Each is billed.',
        ],
        self::AUDIT_PERMISSION => [
            'label' => 'Audit history',
            'description' => 'See who requested, viewed and downloaded CIBIL reports, and the provider\'s reference for each request.',
        ],
    ];

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

    /** Module access plus, for anything beyond it, the finer permission. */
    public static function has(?User $user, string $permission): bool
    {
        if (! self::canUse($user)) {
            return false;
        }

        return $permission === self::PERMISSION
            || self::canManage($user)
            || $user->checkPermissionTo($permission);
    }

    public static function canRequest(?User $user, CibilRequestType $type): bool
    {
        return self::has($user, match ($type) {
            CibilRequestType::Score => self::PERMISSION,
            CibilRequestType::FullReport => self::FULL_REPORT_PERMISSION,
            CibilRequestType::Pdf => self::PDF_PERMISSION,
        });
    }

    public static function canViewFullReport(?User $user): bool
    {
        return self::has($user, self::FULL_REPORT_PERMISSION);
    }

    public static function canDownloadPdf(?User $user): bool
    {
        return self::has($user, self::PDF_PERMISSION);
    }

    public static function canRefresh(?User $user): bool
    {
        return self::has($user, self::REFRESH_PERMISSION);
    }

    public static function canViewAudit(?User $user): bool
    {
        return self::has($user, self::AUDIT_PERMISSION);
    }

    /**
     * Request types this login may ask for.
     *
     * @return list<CibilRequestType>
     */
    public static function requestableTypes(?User $user): array
    {
        return array_values(array_filter(
            CibilRequestType::cases(),
            fn (CibilRequestType $type): bool => self::canRequest($user, $type),
        ));
    }

    /**
     * @return list<string>
     */
    public static function grantedRoleNames(string $permission = self::PERMISSION): array
    {
        return self::permission($permission)->roles()->orderBy('name')->pluck('name')->all();
    }

    /**
     * Logins holding the permission directly, not through a role.
     *
     * @return list<int>
     */
    public static function grantedUserIds(string $permission = self::PERMISSION): array
    {
        return self::permission($permission)->users()->pluck('users.id')->map(fn (mixed $id): int => (int) $id)->all();
    }

    /**
     * Replaces the whole grant of one permission in one go with what the
     * Admin picked.
     *
     * @param  array<int, string>  $roleNames
     * @param  array<int, int|string>  $userIds
     */
    public static function sync(array $roleNames, array $userIds, string $permission = self::PERMISSION): void
    {
        $permission = self::permission($permission);

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
     * The migrations create them; findOrCreate keeps a fresh test database
     * (or a forgotten migration) from breaking the access page.
     */
    public static function permission(string $name = self::PERMISSION): Permission
    {
        /** @var Permission */
        return Permission::findOrCreate($name, 'web');
    }
}
