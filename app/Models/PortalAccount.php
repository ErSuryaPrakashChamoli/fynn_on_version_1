<?php

namespace App\Models;

use App\Enums\PortalRole;
use App\Enums\PortalType;
use Database\Factories\PortalAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The record that narrows a user to one portal.
 *
 * A user WITHOUT one of these is an ordinary LMS user and is untouched by
 * any of this. A user WITH one can only ever reach the portal named here,
 * can only ever see rows belonging to `tenant_id`, and — critically —
 * is refused by User::canAccessPanel() on the admin panel regardless of
 * what Spatie roles they might somehow acquire.
 *
 * isUsable() is the single expiry/active check; every gate calls it
 * rather than re-deriving the rule, so an expired demo link stops
 * working everywhere at once.
 */
class PortalAccount extends Model
{
    /** @use HasFactory<PortalAccountFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'tenant_id',
        'portal',
        'portal_role',
        'is_active',
        'expires_at',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'portal' => PortalType::class,
            'portal_role' => PortalRole::class,
            'is_active' => 'boolean',
            'expires_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Whether this account may be used at all right now. Deactivation and
     * expiry are separate switches so a demo can be revoked immediately
     * without disturbing its scheduled end date.
     */
    public function isUsable(): bool
    {
        return $this->is_active && ! $this->hasExpired();
    }

    public function isTrainer(): bool
    {
        return $this->portal_role === PortalRole::Trainer;
    }

    public function isTrainee(): bool
    {
        return $this->portal_role === PortalRole::Trainee;
    }

    public function isDemo(): bool
    {
        return $this->portal_role === PortalRole::Demo;
    }
}
