<?php

namespace App\Models;

use Database\Factories\DataDownloadAuthenticatorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The owner's authenticator app (Google / Microsoft Authenticator) that
 * approves every Leads / Customers data download. Exactly one row exists
 * once the owner has set it up on the Data Download page; the TOTP secret
 * is stored encrypted and never shown again after setup.
 *
 * last_used_timestep is the 30-second TOTP step of the last code that
 * approved something, so the same code can never approve twice.
 *
 * Managed only through App\Services\DataDownloadAuthenticatorService.
 *
 * @property int $id
 * @property string $owner_name
 * @property string $secret
 * @property int|null $last_used_timestep
 * @property Carbon|null $last_used_at
 * @property int|null $configured_by
 * @property Carbon|null $configured_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $configuredBy
 */
class DataDownloadAuthenticator extends Model
{
    /** @use HasFactory<DataDownloadAuthenticatorFactory> */
    use HasFactory;

    protected $fillable = [
        'owner_name',
        'secret',
        'last_used_timestep',
        'last_used_at',
        'configured_by',
        'configured_at',
    ];

    protected $hidden = [
        'secret',
    ];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'last_used_timestep' => 'integer',
            'last_used_at' => 'datetime',
            'configured_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function configuredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'configured_by');
    }
}
