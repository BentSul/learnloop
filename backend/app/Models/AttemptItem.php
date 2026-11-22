<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Question content and metadata frozen when the attempt started (spec §6.5).
 * marking_snapshot is the protected answer key: hidden by default.
 */
class AttemptItem extends Model
{
    protected $fillable = [
        'attempt_id', 'question_id', 'position', 'question_type_snapshot', 'question_text_snapshot',
        'topic_id_snapshot', 'difficulty_snapshot', 'max_marks_snapshot',
        'options_snapshot', 'option_order', 'marking_snapshot',
    ];

    protected $hidden = ['marking_snapshot'];

    protected function casts(): array
    {
        return [
            'options_snapshot' => 'array',
            'option_order' => 'array',
            'marking_snapshot' => 'array',
            'max_marks_snapshot' => 'decimal:2',
        ];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(Attempt::class);
    }
}
