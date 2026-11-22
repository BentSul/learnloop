<?php

namespace App\Services\Adaptive\Recommendations;

use App\Enums\Adaptive\AttemptStatus;
use App\Enums\Adaptive\GoalType;
use App\Enums\Adaptive\PerformanceStatus;
use App\Enums\Adaptive\RecommendationState as State;
use App\Models\Course;
use App\Models\LearnerTopicGoal;
use App\Models\Recommendation;
use App\Models\RecommendationEvent;
use App\Models\Topic;
use App\Models\User;
use App\Services\Adaptive\Calculation\TopicAssessor;
use App\Services\Adaptive\Evidence\EvidenceAssembler;
use App\Services\Adaptive\TopicPerformanceService;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Keeps stored recommendations in line with the learner's current situation
 * (spec §10.7 reconciliation, §10.14 concurrency).
 *
 * Run it on every recommendations read and after evidence changes. It:
 *  1. reactivates or retires postponed recommendations whose reminder is due
 *  2. completes accepted recommendations with a qualifying activity (before supersession)
 *  3–4. compares each topic's desired signature with its MOST RECENT recommendation
 *  5. carries a future reminder over when a postponed recommendation is superseded
 *
 * T3: a dismissed recommendation with the same signature is never resurrected.
 * Refinement (proposed D18): completed, obsolete and superseded recommendations do not block a
 * new recommendation with the same signature — otherwise a learner who completes practice but
 * still needs help would never be recommended anything again.
 */
final class RecommendationEngine
{
    public function __construct(
        private readonly RecommendationRules $rules = new RecommendationRules(),
        private readonly EvidenceAssembler $assembler = new EvidenceAssembler(),
        private readonly TopicAssessor $assessor = new TopicAssessor(),
        private readonly TopicPerformanceService $presenter = new TopicPerformanceService(),
    ) {
    }

    public function reconcile(User $learner, Course $course, ?CarbonInterface $now = null): void
    {
        $now ??= now();
        $desired = $this->desiredForCourse($learner, $course, $now);

        try {
            $this->reconcileLocked($learner, $course, $desired, $now);
        } catch (UniqueConstraintViolationException) {
            // A concurrent request created a recommendation first: retry once from fresh state.
            $this->reconcileLocked($learner, $course, $desired, $now);
        }
    }

    /**
     * Desired recommendation per active topic, with the basis that explains it.
     *
     * @return array<int, array{topic: Topic, desired: ?DesiredRecommendation, basis: array<string, mixed>}>
     */
    public function desiredForCourse(User $learner, Course $course, CarbonInterface $now): array
    {
        $evidence = $this->assembler->forLearnerCourse($learner->id, $course->id);
        $goals = LearnerTopicGoal::where('learner_id', $learner->id)->whereNull('removed_at')->get()
            ->groupBy('topic_id');
        $at = DateTimeImmutable::createFromInterface($now);

        $result = [];
        foreach ($course->topics()->where('status', 'active')->orderBy('id')->get() as $topic) {
            $assessment = $this->assessor->assess($evidence->forTopic($topic->id), $at);
            $types = $goals->get($topic->id, collect())->map(fn ($g) => $g->goal_type);

            $result[$topic->id] = [
                'topic' => $topic,
                'desired' => $this->rules->desired(
                    $topic->id,
                    $assessment,
                    $types->contains(GoalType::Help),
                    $types->contains(GoalType::Mastery),
                ),
                'basis' => $this->presenter->present($topic, $assessment, $evidence->pendingForTopic($topic->id)),
            ];
        }

        return $result;
    }

    /** Course-level "Take a diagnostic quiz" prompt (§10.3): an unassessed topic with no goal or help request. */
    public function showDiagnosticPrompt(User $learner, Course $course, ?CarbonInterface $now = null): bool
    {
        foreach ($this->desiredForCourse($learner, $course, $now ?? now()) as $entry) {
            if ($entry['basis']['status'] === PerformanceStatus::NotEnoughEvidence->value && $entry['desired'] === null) {
                return true;
            }
        }

        return false;
    }

    private function reconcileLocked(User $learner, Course $course, array $desired, CarbonInterface $now): void
    {
        DB::transaction(function () use ($learner, $course, $desired, $now) {
            // Serialise reconciliation per learner (§10.14).
            User::whereKey($learner->id)->lockForUpdate()->first();

            $topicIds = $course->topics()->pluck('id');
            $open = Recommendation::where('learner_id', $learner->id)
                ->whereIn('topic_id', $topicIds)
                ->whereIn('state', State::openValues())
                ->lockForUpdate()
                ->get();

            // Archived topics: open recommendations become obsolete (§3.1).
            foreach ($open as $rec) {
                if (! isset($desired[$rec->topic_id])) {
                    $this->transition($rec, State::Obsolete, 'system', $now, ['obsolete_at' => $now]);
                }
            }

            foreach ($open as $rec) {
                if (! isset($desired[$rec->topic_id]) || ! $rec->state->isOpen()) {
                    continue;
                }
                $want = $desired[$rec->topic_id]['desired'];

                // Step 1: due reminders.
                if ($rec->state === State::Postponed && $rec->reminder_at !== null && $rec->reminder_at->lte($now)) {
                    $want?->signature === $rec->signature
                        ? $this->transition($rec, State::Active, 'system', $now)
                        : $this->transition($rec, State::Obsolete, 'system', $now, ['obsolete_at' => $now]);
                }

                // Step 2: completion before supersession.
                if ($rec->state === State::Accepted && $this->hasQualifyingActivity($learner, $rec)) {
                    $this->transition($rec, State::Completed, 'system', $now, ['completed_at' => $now]);
                }
            }

            // Steps 3–5.
            foreach ($desired as $topicId => $entry) {
                $want = $entry['desired'];
                $latest = Recommendation::where('learner_id', $learner->id)->where('topic_id', $topicId)
                    ->orderByDesc('created_at')->orderByDesc('id')->first();

                if ($latest === null) {
                    if ($want !== null) {
                        $this->create($learner, $topicId, $want, $entry['basis'], $now);
                    }
                    continue;
                }

                if ($want !== null && $latest->signature === $want->signature) {
                    // Same situation. Dismissed stays dismissed (T3); open stays as it is.
                    if (in_array($latest->state, [State::Completed, State::Obsolete, State::Superseded], true)) {
                        $this->create($learner, $topicId, $want, $entry['basis'], $now);
                    }
                    continue;
                }

                if ($latest->state->isOpen()) {
                    if ($want === null) {
                        $this->transition($latest, State::Obsolete, 'system', $now, ['obsolete_at' => $now]);
                        continue;
                    }

                    // Update the old row before inserting, so the one-open index is never violated.
                    $carryReminder = $latest->state === State::Postponed && $latest->reminder_at?->gt($now)
                        ? $latest->reminder_at : null;
                    $this->transition($latest, State::Superseded, 'system', $now, ['superseded_at' => $now]);
                    $new = $this->create($learner, $topicId, $want, $entry['basis'], $now, $carryReminder);
                    $latest->update(['superseded_by_id' => $new->id]);
                    continue;
                }

                if ($want !== null) {
                    $this->create($learner, $topicId, $want, $entry['basis'], $now);
                }
            }
        });
    }

    /** Accepted → completed when the learner did assessed work on the topic after accepting (§10.7 step 2). */
    private function hasQualifyingActivity(User $learner, Recommendation $rec): bool
    {
        $since = $rec->accepted_at;

        $quiz = DB::table('attempts as at')
            ->join('attempt_items as ai', 'ai.attempt_id', '=', 'at.id')
            ->where('at.learner_id', $learner->id)
            ->whereIn('at.status', AttemptStatus::counting())
            ->where('at.finalised_at', '>', $since)
            ->where('ai.topic_id_snapshot', $rec->topic_id)
            ->exists();

        if ($quiz) {
            return true;
        }

        return DB::table('external_results as er')
            ->join('external_result_items as eri', 'eri.external_result_id', '=', 'er.id')
            ->where('er.learner_id', $learner->id)
            ->where('er.posted', true)
            ->where('er.synced_at', '>', $since)
            ->where('eri.topic_id', $rec->topic_id)
            ->exists();
    }

    private function create(User $learner, int $topicId, DesiredRecommendation $want, array $basis, CarbonInterface $now, ?CarbonInterface $reminderAt = null): Recommendation
    {
        $state = $reminderAt !== null ? State::Postponed : State::Active;

        $rec = Recommendation::create([
            'learner_id' => $learner->id,
            'topic_id' => $topicId,
            'signature' => $want->signature,
            'status_at_creation' => $want->status,
            'primary_reason' => $want->primaryReason,
            'reason_codes' => $want->reasonCodes,
            'priority' => $want->priority,
            'basis' => $basis,
            'state' => $state,
            'reminder_at' => $reminderAt,
        ]);
        $rec->created_at = $now;
        $rec->save();

        RecommendationEvent::create([
            'recommendation_id' => $rec->id, 'learner_id' => $learner->id,
            'from_state' => null, 'to_state' => $state->value, 'actor' => 'system', 'reminder_at' => $reminderAt,
        ]);

        return $rec;
    }

    /** State change + its event, in the caller's transaction (§10.8). */
    public function transition(Recommendation $rec, State $to, string $actor, CarbonInterface $now, array $extra = []): void
    {
        $from = $rec->state;
        $rec->update(['state' => $to] + $extra);

        RecommendationEvent::create([
            'recommendation_id' => $rec->id, 'learner_id' => $rec->learner_id,
            'from_state' => $from->value, 'to_state' => $to->value, 'actor' => $actor,
            'reminder_at' => $extra['reminder_at'] ?? null,
        ]);
    }
}
