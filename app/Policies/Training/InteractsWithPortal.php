<?php

namespace App\Policies\Training;

use App\Models\PortalAccount;
use App\Models\User;
use App\Support\Portal\PortalContext;

/**
 * Shared portal lookups for the training policies.
 *
 * Every method fails closed: no portal account and no LMS Admin role
 * means no training access at all, so a user who somehow reaches an
 * Academy URL without going through the panel guard still gets nothing.
 */
trait InteractsWithPortal
{
    protected function account(User $user): ?PortalAccount
    {
        return app(PortalContext::class)->forUser($user);
    }

    /**
     * Trainers, plus LMS Admins who author content from the inside.
     */
    protected function isTrainer(User $user): bool
    {
        $account = $this->account($user);

        if ($account === null) {
            return $user->hasRole('Admin');
        }

        return $account->isUsable() && $account->isTrainer();
    }

    protected function isTrainee(User $user): bool
    {
        $account = $this->account($user);

        return $account !== null && $account->isUsable() && $account->isTrainee();
    }

    protected function inAcademy(User $user): bool
    {
        return $this->isTrainer($user) || $this->isTrainee($user);
    }

    /**
     * Tenant equality check used by every record-level method. A trainer
     * from tenant A must not act on tenant B's course even though both
     * are "trainers".
     */
    protected function sharesTenant(User $user, ?int $tenantId): bool
    {
        if ($tenantId === null) {
            return false;
        }

        $account = $this->account($user);

        if ($account === null) {
            return $user->hasRole('Admin');
        }

        return $account->tenant_id === $tenantId;
    }
}
