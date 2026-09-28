<?php

namespace App\Models;

use App\Enums\ComplaintRouting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A kind of complaint (Fynn-On, IT, Workspace, Asset, ...) and how it finds
 * its handlers: a team named by Spatie roles, or a supervisor the person
 * raising it picks from their own reporting line. Managed by the Admin
 * from the Help Desk settings.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property ComplaintRouting $routing
 * @property list<string>|null $handler_roles
 * @property bool $is_active
 * @property int $sort_order
 * @property-read Collection<int, ComplaintReason> $reasons
 */
class ComplaintCategory extends Model
{
    protected $fillable = [
        'name',
        'description',
        'routing',
        'handler_roles',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'routing' => ComplaintRouting::class,
        'handler_roles' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function reasons(): HasMany
    {
        return $this->hasMany(ComplaintReason::class, 'category_id')->orderBy('sort_order')->orderBy('name');
    }

    public function complaints(): HasMany
    {
        return $this->hasMany(Complaint::class, 'category_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    public function routesToSupervisor(): bool
    {
        return $this->routing === ComplaintRouting::Supervisor;
    }

    /**
     * The roles whose holders handle this category's tickets.
     *
     * @return list<string>
     */
    public function handlerRoles(): array
    {
        return array_values(array_filter(array_map('strval', $this->handler_roles ?? [])));
    }

    /**
     * "IT" / "Admin, IT" — for labels and notifications.
     */
    public function handlerLabel(): string
    {
        return $this->routesToSupervisor()
            ? 'a supervisor you choose'
            : (implode(', ', $this->handlerRoles()) ?: 'Admin').' team';
    }

    /**
     * @return array<int, string>
     */
    public static function options(): array
    {
        return static::query()->active()->ordered()->pluck('name', 'id')->all();
    }
}
