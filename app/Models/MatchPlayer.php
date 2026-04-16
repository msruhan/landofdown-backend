<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatchPlayer extends Model
{
    protected $fillable = [
        'match_id',
        'player_id',
        'team',
        'hero_id',
        'role_id',
        'kills',
        'deaths',
        'assists',
        'rating',
        'medal',
        'result',
    ];

    protected function casts(): array
    {
        return [
            'kills' => 'integer',
            'deaths' => 'integer',
            'assists' => 'integer',
            'rating' => 'decimal:1',
        ];
    }

    public function gameMatch(): BelongsTo
    {
        return $this->belongsTo(GameMatch::class, 'match_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function hero(): BelongsTo
    {
        return $this->belongsTo(Hero::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
