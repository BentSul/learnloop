<?php

namespace App\Services\Adaptive;

use App\Enums\Adaptive\GoalType;
use App\Models\LearnerTopicGoal;
use App\Models\Topic;
use App\Models\User;
use App\Services\Adaptive\Recommendations\RecommendationEngine;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;

/**
 * Mastery goals and help requests (spec §9). Preferences only: they never change performance,
 * coverage, confidence or status; they only affect recommendations, which are reconciled after
 * every change.
 */
final class GoalService
{
    public function __construct(private readonly RecommendationEngine $engine = new RecommendationEngine())
    {
    }

    /** Adds a goal or help request. Adding one that already exists returns it unchanged. */
    public function add(User $learner, Topic $topic, GoalType $type): LearnerTopicGoal
    {
        $existing = $this->active($learner, $topic, $type);
        if ($existing !== null) {
            return $existing;
        }

        try {
            $goal = LearnerTopicGoal::create([
                'learner_id' => $learner->id, 'topic_id' => $topic->id, 'goal_type' => $type,
            ]);
        } catch (UniqueConstraintViolationException) {
            $goal = $this->active($learner, $topic, $type); // concurrent add
        }

        $this->engine->reconcile($learner, $topic->course);

        return $goal;
    }

    /** Soft-deletes the goal or help request (history kept). */
    public function remove(User $learner, Topic $topic, GoalType $type): void
    {
        $goal = $this->active($learner, $topic, $type);
        if ($goal === null) {
            return;
        }

        $goal->update(['removed_at' => now()]);
        $this->engine->reconcile($learner, $topic->course);
    }

    /** @return Collection<int, LearnerTopicGoal> active goals and help requests, with topics */
    public function forLearner(User $learner): Collection
    {
        return LearnerTopicGoal::with('topic')->where('learner_id', $learner->id)
            ->whereNull('removed_at')->orderBy('topic_id')->get();
    }

    private function active(User $learner, Topic $topic, GoalType $type): ?LearnerTopicGoal
    {
        return LearnerTopicGoal::where('learner_id', $learner->id)->where('topic_id', $topic->id)
            ->where('goal_type', $type->value)->whereNull('removed_at')->first();
    }
}
