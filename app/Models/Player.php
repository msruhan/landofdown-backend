<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Player extends Model
{
    protected $fillable = ['username', 'avatar_url'];

    public function matchPlayers(): HasMany
    {
        return $this->hasMany(MatchPlayer::class);
    }

    public function gameMatches(): HasManyThrough
    {
        return $this->hasManyThrough(
            GameMatch::class,
            MatchPlayer::class,
            'player_id',
            'id',
            'id',
            'match_id'
        );
    }
}
