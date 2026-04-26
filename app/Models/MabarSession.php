<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MabarSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'host_user_id',
        'title',
        'type',
        'vibe',
        'rank_requirement',
        'starts_at',
        'ends_at',
        'recurrence',
        'recurrence_days',
        'max_slots',
        'status',
        'voice_platform',
        'discord_link',
        'room_id',
        'notes',
        'is_featured',
        'pinned_message_id',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'recurrence_days' => 'array',
        'is_featured' => 'boolean',
        'max_slots' => 'integer',
    ];

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_user_id');
    }

    public function slots(): HasMany
    {
        return $this->hasMany(MabarSlot::class, 'session_id')->orderBy('slot_index');
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(MabarRating::class, 'session_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(MabarMessage::class, 'session_id');
    }

    public function filledSlotsCount(): int
    {
        return $this->slots->whereIn('status', ['pending', 'confirmed'])->count();
    }
}
