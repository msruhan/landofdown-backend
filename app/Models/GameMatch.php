<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GameMatch extends Model
{
    protected $table = 'game_matches';

    protected $fillable = [
        'match_date',
        'duration',
        'team_a_name',
        'team_b_name',
        'winner',
        'patch_id',
        'notes',
        'screenshot_path',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'match_date' => 'date',
        ];
    }

    public function matchPlayers(): HasMany
    {
        return $this->hasMany(MatchPlayer::class, 'match_id');
    }

    public function teamAPlayers(): HasMany
    {
        return $this->hasMany(MatchPlayer::class, 'match_id')->where('team', 'team_a');
    }

    public function teamBPlayers(): HasMany
    {
        return $this->hasMany(MatchPlayer::class, 'match_id')->where('team', 'team_b');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function screenshots(): HasMany
    {
        return $this->hasMany(UploadedScreenshot::class, 'match_id');
    }

    public function patch(): BelongsTo
    {
        return $this->belongsTo(Patch::class);
    }

    public function draftPicks(): HasMany
    {
        return $this->hasMany(DraftPick::class, 'match_id');
    }
}
