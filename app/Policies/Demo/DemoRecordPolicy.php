<?php

namespace App\Policies\Demo;

use App\Models\Demo\DemoModel;
use App\Models\User;
use App\Support\Portal\PortalContext;

/**
 * One policy for every sandbox model.
 *
 * Demo records carry no per-user ownership — a prospect exploring the
 * sandbox is meant to see all of it — so the only questions are "is this
 * a demo portal user?" and "is this row in their tenant?". Deletes are
 * refused outright: a prospect clicking through the product should never
 * be able to empty the dataset they are being shown. Everything else
 * they change is reverted by DemoResetService.
 */
class DemoRecordPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isDemoUser($user);
    }

    public function view(User $user, DemoModel $record): bool
    {
        return $this->isDemoUser($user) && $this->sharesTenant($user, $record->tenant_id);
    }

    public function create(User $user): bool
    {
        return $this->isDemoUser($user);
    }

    public function update(User $user, DemoModel $record): bool
    {
        return $this->view($user, $record);
    }

    public function delete(User $user, DemoModel $record): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    protected function isDemoUser(User $user): bool
    {
        $account = app(PortalContext::class)->forUser($user);

        return $account !== null && $account->isUsable() && $account->isDemo();
    }

    protected function sharesTenant(User $user, ?int $tenantId): bool
    {
        return $tenantId !== null
            && app(PortalContext::class)->forUser($user)?->tenant_id === $tenantId;
    }
}
