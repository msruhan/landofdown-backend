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

    public function setMedalAttribute(?string $value): void
    {
        if ($value === null) {
            $this->attributes['medal'] = null;

            return;
        }

        // SQLite schema still allows only: mvp, gold, silver, bronze.
        if ($this->getConnection()->getDriverName() === 'sqlite' && in_array($value, ['mvp_win', 'mvp_lose'], true)) {
            $value = 'mvp';
        }

        $this->attributes['medal'] = $value;
    }

    public function getMedalAttribute(?string $value): ?string
    {
        if ($value !== 'mvp') {
            return $value;
        }

        $result = $this->attributes['result'] ?? null;
        if ($result === 'win') {
            return 'mvp_win';
        }
        if ($result === 'lose') {
            return 'mvp_lose';
        }

        return 'mvp_win';
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
