<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One state transition of a recommendation (spec §10.8, C11). Append-only. */
class RecommendationEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['recommendation_id', 'learner_id', 'from_state', 'to_state', 'actor', 'reminder_at'];

    protected function casts(): array
    {
        return ['reminder_at' => 'datetime'];
    }
}
