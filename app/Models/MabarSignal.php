<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MabarSignal extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'active_until',
        'mood_tag',
        'note',
    ];

    protected $casts = [
        'active_until' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->active_until && $this->active_until->isFuture();
    }
}
