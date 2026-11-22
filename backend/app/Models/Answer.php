<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Answer extends Model
{
    protected $fillable = ['attempt_id', 'question_id', 'response', 'answered_at'];

    protected function casts(): array
    {
        return ['response' => 'array', 'answered_at' => 'datetime'];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(Attempt::class);
    }

    public function markVersions(): HasMany
    {
        return $this->hasMany(MarkVersion::class)->orderBy('version');
    }
}
