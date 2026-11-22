<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Maps a Canvas item ('' criterion) or rubric criterion to a topic (v0.8 §3.3). */
class CanvasItemMapping extends Model
{
    protected $attributes = ['source_group' => 'canvas'];

    protected $fillable = [
        'course_id', 'canvas_assignment_id', 'canvas_criterion_id', 'topic_id', 'difficulty', 'source_group', 'mapped_by',
    ];

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }
}
