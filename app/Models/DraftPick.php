<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DraftPick extends Model
{
    protected $fillable = [
        'match_id',
        'team',
        'action',
        'order_index',
        'hero_id',
    ];

    protected function casts(): array
    {
        return [
            'order_index' => 'integer',
        ];
    }

    public function gameMatch(): BelongsTo
    {
        return $this->belongsTo(GameMatch::class, 'match_id');
    }

    public function hero(): BelongsTo
    {
        return $this->belongsTo(Hero::class);
    }
}
