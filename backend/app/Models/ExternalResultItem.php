<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Topic-level evidence derived from an external result through the mappings. */
class ExternalResultItem extends Model
{
    protected $fillable = [
        'external_result_id', 'evidence_key', 'topic_id', 'difficulty', 'source_group', 'earned_marks', 'max_marks',
    ];

    public function result(): BelongsTo
    {
        return $this->belongsTo(ExternalResult::class, 'external_result_id');
    }
}
