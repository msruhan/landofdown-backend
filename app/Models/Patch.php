<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Patch extends Model
{
    protected $fillable = ['version', 'name', 'release_date', 'notes'];

    protected function casts(): array
    {
        return [
            'release_date' => 'date',
        ];
    }

    public function gameMatches(): HasMany
    {
        return $this->hasMany(GameMatch::class);
    }
}
