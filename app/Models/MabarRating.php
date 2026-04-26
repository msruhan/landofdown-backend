<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MabarRating extends Model
{
    use HasFactory;

    protected $fillable = [
        'session_id',
        'from_user_id',
        'to_user_id',
        'stars',
        'tags',
        'comment',
    ];

    protected $casts = [
        'tags' => 'array',
        'stars' => 'integer',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(MabarSession::class, 'session_id');
    }

    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }
}
