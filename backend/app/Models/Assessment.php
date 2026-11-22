<?php

namespace App\Models;

use App\Enums\Adaptive\AssessmentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A LearnLoop practice set or learner revision quiz (v0.8 §5). */
class Assessment extends Model
{
    protected $fillable = [
        'course_id', 'assessment_type', 'owner_learner_id', 'topic_id', 'title', 'status', 'created_by',
    ];

    protected function casts(): array
    {
        return ['assessment_type' => AssessmentType::class];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(AssessmentItem::class)->orderBy('position');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(Attempt::class);
    }
}
