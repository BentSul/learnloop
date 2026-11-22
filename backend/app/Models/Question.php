<?php

namespace App\Models;

use App\Enums\Adaptive\Difficulty;
use App\Enums\Adaptive\QuestionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Question bank entry (spec §3.2). answer_data holds the answer key and must never be
 * serialised to learners: it is hidden by default.
 */
class Question extends Model
{
    protected $attributes = ['status' => 'active', 'source' => 'learnloop', 'difficulty' => 'medium'];

    protected $fillable = [
        'topic_id', 'question_type', 'question_text', 'difficulty', 'max_marks',
        'answer_data', 'explanation', 'source', 'status', 'created_by',
    ];

    protected $hidden = ['answer_data'];

    protected function casts(): array
    {
        return [
            'question_type' => QuestionType::class,
            'difficulty' => Difficulty::class,
            'answer_data' => 'array',
            'max_marks' => 'decimal:2',
        ];
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }
}
