<?php

namespace App\Models\Demo;

use App\Support\Demo\DemoDatabase;
use Database\Factories\Demo\DemoUserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A login for the /demo sandbox, stored in demo_users on the demo
 * database and authenticated by the `demo` guard.
 *
 * Entirely separate from App\Models\User: no Spatie roles, no employee,
 * no login-session tracking, and canAccessPanel() only ever admits the
 * demo panel — a DemoUser can never be a user of /admin.
 */
class DemoUser extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<DemoUserFactory> */
    use HasFactory;

    protected $table = 'demo_users';

    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
        'expires_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'expires_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function getConnectionName(): ?string
    {
        return DemoDatabase::connectionName();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'demo' && $this->isUsable();
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Deactivation and expiry are separate switches so a demo login can
     * be revoked immediately without disturbing its scheduled end date.
     */
    public function isUsable(): bool
    {
        return $this->is_active && ! $this->hasExpired();
    }
}
