<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;

use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password','is_active'])]
#[Hidden(['password', 'remember_token'])]


class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable ,HasRoles;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean'
            ];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class,'employee_id');
    }

    public function canAccessPanel(Panel $panel): bool
    {
        // Check if email matches, or restore your Spatie roles logic here if needed
        // return $this->email === 'prakash@gmail.com';
        return true;
    }




}
