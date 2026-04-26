<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MabarMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'session_id',
        'user_id',
        'kind',
        'body',
        'reactions',
        'reply_to_id',
        'is_pinned',
    ];

    protected $casts = [
        'reactions' => 'array',
        'is_pinned' => 'boolean',
        'deleted_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(MabarSession::class, 'session_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(MabarMessage::class, 'reply_to_id');
    }
}
