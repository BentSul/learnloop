<?php

namespace App\Models;

use App\Enums\Adaptive\MarkVersionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only mark for an answer, with evidence snapshots (spec §4.4–§4.5).
 * Never update marks in place; create a new version instead.
 */
class MarkVersion extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'answer_id', 'version', 'status', 'reason', 'earned_marks', 'max_marks', 'topic_id',
        'difficulty', 'source_group', 'evidence_key', 'finalised_at', 'marked_by', 'marked_at',
        'approved_by', 'approved_at', 'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => MarkVersionStatus::class,
            'finalised_at' => 'datetime',
            'marked_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function answer(): BelongsTo
    {
        return $this->belongsTo(Answer::class);
    }
}
