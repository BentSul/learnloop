<?php

namespace App\Services\Adaptive\Recommendations;

use App\Enums\Adaptive\RecommendationState as State;
use App\Models\Course;
use App\Models\Recommendation;
use App\Models\User;
use App\Services\Adaptive\CourseAccess;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Lists recommendations for the Recommendations page and dashboard (spec §10.11–§10.12).
 * Reconciles first, so due reminders and new evidence are reflected on every read.
 */
final class RecommendationQuery
{
    public const SECTIONS = ['active', 'accepted', 'postponed', 'dismissed', 'history'];

    public function __construct(
        private readonly RecommendationEngine $engine,
        private readonly CourseAccess $access,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function section(User $learner, string $section, ?CarbonInterface $now = null): array
    {
        $this->reconcileAll($learner, $now);

        $states = $section === 'history'
            ? [State::Completed->value, State::Obsolete->value, State::Superseded->value]
            : [$section];

        $recs = Recommendation::with('topic')->where('learner_id', $learner->id)->whereIn('state', $states)->get();

        $sorted = in_array($section, ['active', 'accepted', 'postponed'], true)
            ? $this->order($recs)
            : $recs->sortByDesc(fn ($r) => $r->updated_at)->values();

        return $sorted->map(fn (Recommendation $r) => RecommendationPresenter::toArray($r))->all();
    }

    /** Dashboard shortlist: the highest-priority active recommendations (default 5). */
    public function shortlist(User $learner, ?CarbonInterface $now = null): array
    {
        $limit = (int) config('adaptive.recommendations.dashboard_shortlist', 5);

        return array_slice($this->section($learner, 'active', $now), 0, $limit);
    }

    public function reconcileAll(User $learner, ?CarbonInterface $now = null): void
    {
        foreach ($this->access->coursesFor($learner) as $course) {
            /** @var Course $course */
            if ($this->access->isStudent($learner, $course)) {
                $this->engine->reconcile($learner, $course, $now);
            }
        }
    }

    /**
     * §10.11: priority, help requested, mastery goal, lower performance first
     * (not assessed last), older evidence first.
     *
     * @param  Collection<int, Recommendation>  $recs
     * @return Collection<int, Recommendation>
     */
    private function order(Collection $recs): Collection
    {
        return $recs->sort(function (Recommendation $a, Recommendation $b) {
            return [
                -$a->priority->rank(),
                in_array('help_requested', $a->reason_codes, true) ? 0 : 1,
                in_array('mastery_goal', $a->reason_codes, true) ? 0 : 1,
                $a->basis['performance_display'] ?? 1000,
                $a->basis['last_assessed_at'] ?? '9999',
                $a->id,
            ] <=> [
                -$b->priority->rank(),
                in_array('help_requested', $b->reason_codes, true) ? 0 : 1,
                in_array('mastery_goal', $b->reason_codes, true) ? 0 : 1,
                $b->basis['performance_display'] ?? 1000,
                $b->basis['last_assessed_at'] ?? '9999',
                $b->id,
            ];
        })->values();
    }
}
