<?php

namespace App\Support\Portal;

use App\Enums\PortalType;
use App\Models\PortalAccount;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The single answer to "which portal is this user in, and which tenant's
 * rows may they see?".
 *
 * Registered as a request-scoped singleton so the middleware, the panel
 * providers, every Academy/Demo resource's getEloquentQuery() and every
 * policy read the same resolved account instead of each re-querying it.
 *
 * Nothing here grants access — it only reports. Enforcement lives in
 * User::canAccessPanel(), RestrictPortalUsers and the policies, all of
 * which fail closed when this returns null.
 */
class PortalContext
{
    protected ?PortalAccount $account = null;

    protected bool $resolved = false;

    public function forUser(?Authenticatable $user): ?PortalAccount
    {
        if (! $user instanceof User) {
            return null;
        }

        return $user->relationLoaded('portalAccount')
            ? $user->portalAccount
            : $user->portalAccount()->with('tenant')->first();
    }

    /**
     * The authenticated user's portal account, memoized for the request.
     * Null means "ordinary LMS user" — every pre-existing user.
     */
    public function account(): ?PortalAccount
    {
        if (! $this->resolved) {
            $this->account = $this->forUser(auth()->user());
            $this->resolved = true;
        }

        return $this->account;
    }

    public function portal(): PortalType
    {
        return $this->account()?->portal ?? PortalType::Admin;
    }

    /**
     * The tenant whose rows the current user may read. Falls back to the
     * production tenant for ordinary LMS users so training content
     * authored by an Admin lands in the right place.
     */
    public function tenant(): ?Tenant
    {
        return $this->account()?->tenant ?? Tenant::where('slug', Tenant::PRODUCTION_SLUG)->first();
    }

    public function tenantId(): ?int
    {
        return $this->tenant()?->getKey();
    }

    public function isTrainer(): bool
    {
        return (bool) $this->account()?->isTrainer();
    }

    public function isTrainee(): bool
    {
        return (bool) $this->account()?->isTrainee();
    }

    public function isDemo(): bool
    {
        return (bool) $this->account()?->isDemo();
    }

    /**
     * True for anyone who is not pinned to a portal — i.e. the existing
     * LMS population. Used by the Academy panel to let an LMS Admin in
     * alongside trainers without giving them a portal account.
     */
    public function isInternalUser(): bool
    {
        return $this->account() === null;
    }

    public function forget(): void
    {
        $this->account = null;
        $this->resolved = false;
    }
}
