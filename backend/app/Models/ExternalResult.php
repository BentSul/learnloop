<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One observed version of a learner's Canvas/Klenam result (v0.8 §3.5). Append-only. */
class ExternalResult extends Model
{
    protected $fillable = [
        'source', 'learner_id', 'course_id', 'external_item_ref', 'external_submission_ref', 'version',
        'score_hash', 'posted', 'excused', 'submitted_at', 'graded_at', 'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'posted' => 'boolean',
            'excused' => 'boolean',
            'submitted_at' => 'datetime',
            'graded_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ExternalResultItem::class);
    }
}
