<?php

namespace App\Services\Portal;

use App\Enums\PortalRole;
use App\Models\PortalAccount;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Portal\PortalAudit;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Creates and revokes portal users.
 *
 * The one thing every caller must not have to remember: a portal user
 * gets NO Spatie role. Minting them without one is what guarantees the
 * production LMS's hasRole('Admin')-style checks can never see them as
 * privileged, so account creation is funnelled through here rather than
 * done inline in seeders and actions.
 */
class PortalAccountService
{
    /**
     * @param  array{name: string, email: string, password?: string}  $attributes
     */
    public function create(
        array $attributes,
        Tenant $tenant,
        PortalRole $role,
        ?DateTimeInterface $expiresAt = null,
    ): PortalAccount {
        return DB::transaction(function () use ($attributes, $tenant, $role, $expiresAt): PortalAccount {
            $user = User::updateOrCreate(
                ['email' => $attributes['email']],
                [
                    'name' => $attributes['name'],
                    'password' => Hash::make($attributes['password'] ?? Str::password(16)),
                    'is_active' => true,
                ]
            );

            /*
             * Defensive: if this email previously belonged to an LMS
             * user, strip every production role before pinning them to a
             * portal. Without this, converting an account would leave the
             * old grants in place.
             */
            $user->syncRoles([]);

            $account = PortalAccount::updateOrCreate(
                ['user_id' => $user->getKey()],
                [
                    'tenant_id' => $tenant->getKey(),
                    'portal' => $role->portal(),
                    'portal_role' => $role,
                    'is_active' => true,
                    'expires_at' => $expiresAt,
                ]
            );

            PortalAudit::portalAccountCreated($account);

            return $account;
        });
    }

    public function revoke(PortalAccount $account): PortalAccount
    {
        $account->forceFill(['is_active' => false])->save();

        PortalAudit::portalAccountRevoked($account);

        return $account;
    }

    public function extend(PortalAccount $account, DateTimeInterface $until): PortalAccount
    {
        $account->forceFill([
            'expires_at' => $until,
            'is_active' => true,
        ])->save();

        return $account;
    }

    /**
     * Deactivate every account whose expiry has passed. Called by the
     * scheduled portal:expire-accounts command.
     */
    public function deactivateExpired(): int
    {
        return PortalAccount::query()
            ->where('is_active', true)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update(['is_active' => false]);
    }
}
