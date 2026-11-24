<?php

namespace App\Models;

use App\Enums\Adaptive\GoalType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A mastery goal or help request (spec §9). Removal is a soft delete via removed_at. */
class LearnerTopicGoal extends Model
{
    protected $fillable = ['learner_id', 'topic_id', 'goal_type', 'removed_at', 'acknowledged_at', 'acknowledged_by'];

    protected function casts(): array
    {
        return ['goal_type' => GoalType::class, 'removed_at' => 'datetime', 'acknowledged_at' => 'datetime'];
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    public function learner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'learner_id');
    }
}
