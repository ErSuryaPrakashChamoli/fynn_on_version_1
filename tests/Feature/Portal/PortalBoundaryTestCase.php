<?php

namespace Tests\Feature\Portal;

use App\Enums\PortalRole;
use App\Models\PortalAccount;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Portal\PortalAccountService;
use App\Support\Portal\PortalContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Shared fixtures for the portal boundary tests.
 *
 * forgetPortalContext() matters more than it looks: PortalContext is a
 * request-scoped singleton that memoizes the resolved account, and a
 * test that acts as two different users in one process would otherwise
 * see the first user's portal for the second user's request — which
 * would make these tests pass for the wrong reason.
 */
abstract class PortalBoundaryTestCase extends TestCase
{
    use RefreshDatabase;

    protected Tenant $production;

    protected Tenant $demoTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->production = Tenant::factory()->production()->create();
        $this->demoTenant = Tenant::factory()->demo()->create();
    }

    protected function makePortalUser(
        PortalRole $role,
        ?Tenant $tenant = null,
        ?string $email = null,
    ): User {
        $account = app(PortalAccountService::class)->create(
            [
                'name' => 'Portal '.$role->value,
                'email' => $email ?? $role->value.'-'.fake()->unique()->numberBetween(1, 999999).'@example.test',
                'password' => 'Password@123',
            ],
            $tenant ?? ($role === PortalRole::Demo ? $this->demoTenant : $this->production),
            $role,
        );

        return $account->user;
    }

    /**
     * An ordinary LMS user — no portal account at all. This is what
     * every pre-existing user in the application looks like, and their
     * behaviour must be unchanged.
     */
    protected function makeInternalUser(?string $role = null): User
    {
        $user = User::factory()->create();

        if ($role !== null) {
            $user->assignRole(Role::findOrCreate($role));
        }

        return $user;
    }

    /**
     * Switch the acting user.
     *
     * The session is flushed as well as the portal context, because
     * Filament's AuthenticateSession middleware pins a session to one
     * user's password hash — swapping users mid-test without this
     * produces a logout redirect that looks exactly like a failed
     * authorization check, and would hide a real regression.
     */
    protected function actingAsPortalUser(User $user): static
    {
        $this->flushSession();
        $this->forgetPortalContext();
        $this->actingAs($user);

        return $this;
    }

    protected function forgetPortalContext(): void
    {
        app(PortalContext::class)->forget();
    }

    protected function accountFor(User $user): PortalAccount
    {
        return PortalAccount::where('user_id', $user->getKey())->firstOrFail();
    }
}
