<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'is_admin', 'username', 'avatar_url'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
        ];
    }

    public function createdMatches(): HasMany
    {
        return $this->hasMany(GameMatch::class, 'created_by');
    }

    public function hostedMabarSessions(): HasMany
    {
        return $this->hasMany(MabarSession::class, 'host_user_id');
    }

    public function mabarSlots(): HasMany
    {
        return $this->hasMany(MabarSlot::class, 'user_id');
    }

    public function mabarSignal(): HasOne
    {
        return $this->hasOne(MabarSignal::class);
    }
}
