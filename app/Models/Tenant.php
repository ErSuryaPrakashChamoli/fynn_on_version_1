<?php

namespace App\Models;

use App\Enums\TenantType;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A company boundary.
 *
 * Two rows exist out of the box: the FynnEdge production tenant (which
 * nothing currently scopes by — the live LMS is unchanged) and the
 * FYNN-ON demo tenant. Additional client tenants can be added without
 * any further schema work.
 */
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    public const PRODUCTION_SLUG = 'fynnedge';

    public const DEMO_SLUG = 'fynn-on-demo';

    protected $fillable = [
        'name',
        'slug',
        'type',
        'is_demo',
        'is_active',
        'brand_name',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'type' => TenantType::class,
            'is_demo' => 'boolean',
            'is_active' => 'boolean',
            'settings' => 'array',
        ];
    }

    public function portalAccounts(): HasMany
    {
        return $this->hasMany(PortalAccount::class);
    }

    public static function production(): self
    {
        return static::where('slug', self::PRODUCTION_SLUG)->firstOrFail();
    }

    public static function demo(): self
    {
        return static::where('slug', self::DEMO_SLUG)->firstOrFail();
    }

    public function isDemo(): bool
    {
        return $this->is_demo || $this->type === TenantType::Demo;
    }

    public function getBrandNameAttribute($value): string
    {
        return $value ?: $this->name;
    }
}
