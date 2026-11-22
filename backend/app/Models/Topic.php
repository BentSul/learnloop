<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A topic within a course (spec §3.1). Archived topics keep their evidence. */
class Topic extends Model
{
    protected $fillable = ['course_id', 'name', 'description', 'learning_outcomes', 'status'];

    protected function casts(): array
    {
        return ['learning_outcomes' => 'array'];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class);
    }
}
