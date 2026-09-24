<?php

namespace App\Policies\Demo;

use App\Models\Demo\DemoModel;
use App\Models\Demo\DemoUser;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * One policy for every sandbox model.
 *
 * before() refuses anyone who is not a DemoUser, so a main
 * App\Models\User — even an Admin — is denied every sandbox ability
 * before any method below is consulted. Demo records carry no per-user
 * ownership: a prospect exploring the sandbox is meant to see all of
 * it. Deletes are refused
 * outright so the dataset cannot be emptied mid-demonstration; anything
 * else they change is reverted by `php artisan demo:reset`.
 */
class DemoRecordPolicy
{
    public function before(Authenticatable $user): ?bool
    {
        return $user instanceof DemoUser ? null : false;
    }

    public function viewAny(DemoUser $user): bool
    {
        return $user->isUsable();
    }

    public function view(DemoUser $user, DemoModel $record): bool
    {
        return $user->isUsable();
    }

    public function create(DemoUser $user): bool
    {
        return $user->isUsable();
    }

    public function update(DemoUser $user, DemoModel $record): bool
    {
        return $user->isUsable();
    }

    public function delete(DemoUser $user, DemoModel $record): bool
    {
        return false;
    }

    public function deleteAny(DemoUser $user): bool
    {
        return false;
    }
}
