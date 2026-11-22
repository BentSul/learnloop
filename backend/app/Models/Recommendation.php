<?php

namespace App\Models;

use App\Enums\Adaptive\Priority;
use App\Enums\Adaptive\RecommendationState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Stored recommendation identity and state (spec §10.5). Change state only through
 * RecommendationEngine / RecommendationActions so every change writes an event.
 */
class Recommendation extends Model
{
    protected $fillable = [
        'learner_id', 'topic_id', 'signature', 'status_at_creation', 'primary_reason', 'reason_codes',
        'priority', 'basis', 'state', 'reminder_at', 'accepted_at', 'dismissed_at', 'restored_at',
        'completed_at', 'obsolete_at', 'superseded_at', 'superseded_by_id',
    ];

    protected $hidden = ['open_key'];

    protected function casts(): array
    {
        return [
            'reason_codes' => 'array',
            'basis' => 'array',
            'priority' => Priority::class,
            'state' => RecommendationState::class,
            'reminder_at' => 'datetime',
            'accepted_at' => 'datetime',
            'dismissed_at' => 'datetime',
            'restored_at' => 'datetime',
            'completed_at' => 'datetime',
            'obsolete_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(RecommendationEvent::class)->orderBy('id');
    }
}
