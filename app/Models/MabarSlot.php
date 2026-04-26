<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MabarSlot extends Model
{
    use HasFactory;

    protected $fillable = [
        'session_id',
        'slot_index',
        'role_preference',
        'user_id',
        'status',
        'joined_at',
        'last_seen_at',
    ];

    protected $casts = [
        'joined_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'slot_index' => 'integer',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(MabarSession::class, 'session_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
