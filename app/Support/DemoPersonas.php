<?php

namespace App\Support;

use App\Models\User;

/**
 * The seeded logins a demo presenter can switch between. Read from
 * config/demo.php so the seeder, the login page and the topbar switcher
 * all agree on the same list.
 */
class DemoPersonas
{
    public static function enabled(): bool
    {
        return (bool) config('demo.enabled');
    }

    /**
     * @return array<string, array{label: string, email: string, description: string}>
     */
    public static function all(): array
    {
        return config('demo.personas', []);
    }

    /**
     * @return array{label: string, email: string, description: string}|null
     */
    public static function find(string $slug): ?array
    {
        return self::all()[$slug] ?? null;
    }

    /**
     * The persona slug a signed-in user is, or null for any other login.
     */
    public static function slugFor(?User $user): ?string
    {
        if (! $user) {
            return null;
        }

        foreach (self::all() as $slug => $persona) {
            if (strcasecmp($persona['email'], (string) $user->email) === 0) {
                return $slug;
            }
        }

        return null;
    }

    public static function userFor(string $slug): ?User
    {
        $persona = self::find($slug);

        if (! $persona) {
            return null;
        }

        return User::query()->where('email', $persona['email'])->first();
    }
}
