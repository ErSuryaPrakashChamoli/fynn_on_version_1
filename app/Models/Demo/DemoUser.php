<?php

namespace App\Models\Demo;

use App\Models\User;
use App\Support\Demo\DemoDatabase;
use Database\Factories\Demo\DemoUserFactory;
use Filament\Panel;

/**
 * The /demo login: a row in the DEMO database's own `users` table,
 * authenticated by the `demo` guard.
 *
 * It extends User so every shared admin resource, page, widget and
 * service works unchanged — roles, the employee behind the login, the
 * reporting hierarchy, notifications and login-session tracking all
 * behave exactly as on /admin. What keeps it apart from the main users:
 *
 *  - getConnectionName() is pinned to the demo connection, so the `demo`
 *    guard can only ever find a user that exists in the demo database —
 *    main credentials cannot log in here, even outside the demo context.
 *    Eloquent hands that connection down to related models it loads
 *    (employee, roles, notifications), so those stay on the demo DB too;
 *  - canAccessPanel() only admits the demo panel, and the /admin panel's
 *    `web` guard never resolves a DemoUser.
 *
 * getMorphClass() reports App\Models\User and guard_name is `web`, because
 * the demo database is a copy of the main schema: its role assignments,
 * notifications and activity rows are keyed the same way the shared code
 * writes them.
 */
class DemoUser extends User
{
    protected $table = 'users';

    /**
     * Spatie reads this instead of deriving the guard from the auth
     * provider (which would be `demo`). Demo roles are seeded on `web`,
     * exactly as the main application's are.
     *
     * @var string
     */
    protected $guard_name = 'web';

    public function getConnectionName(): ?string
    {
        return DemoDatabase::connectionName();
    }

    public function getMorphClass(): string
    {
        return User::class;
    }

    /**
     * Relations inherited from User (portal account, login sessions, ...)
     * key on `user_id`, not on a `demo_user_id` derived from this class.
     */
    public function getForeignKey(): string
    {
        return 'user_id';
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'demo' && ! $this->isDeactivated();
    }

    protected static function newFactory(): DemoUserFactory
    {
        return DemoUserFactory::new();
    }
}
