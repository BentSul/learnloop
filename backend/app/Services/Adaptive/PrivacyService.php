<?php

namespace App\Services\Adaptive;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A learner's own data: download everything LearnLoop holds about them, or delete it.
 * Exports never contain Canvas tokens, answer keys or other people's data.
 */
class PrivacyService
{
    public function __construct(
        private readonly CourseAccess $access,
        private readonly TopicPerformanceService $performance,
    ) {
    }

    /** @return array<string, mixed> */
    public function export(User $user): array
    {
        $connection = DB::table('canvas_connections')->where('user_id', $user->id)->first();

        $attempts = DB::table('attempts')->join('assessments', 'assessments.id', '=', 'attempts.assessment_id')
            ->where('attempts.learner_id', $user->id)->orderBy('attempts.id')
            ->get(['attempts.id', 'assessments.title', 'attempts.attempt_number', 'attempts.status', 'attempts.started_at', 'attempts.submitted_at', 'attempts.finalised_at']);
        $answers = DB::table('answers')->join('attempt_items', function ($join) {
            $join->on('attempt_items.attempt_id', '=', 'answers.attempt_id')->on('attempt_items.question_id', '=', 'answers.question_id');
        })->whereIn('answers.attempt_id', $attempts->pluck('id'))->orderBy('attempt_items.position')
            ->get(['answers.id', 'answers.attempt_id', 'attempt_items.question_text_snapshot', 'attempt_items.options_snapshot', 'answers.response', 'answers.answered_at']);
        $marks = DB::table('mark_versions')->whereIn('answer_id', $answers->pluck('id'))->orderBy('version')
            ->get(['answer_id', 'version', 'status', 'earned_marks', 'max_marks', 'finalised_at'])->groupBy('answer_id');

        $results = DB::table('external_results')->where('learner_id', $user->id)->orderBy('id')->get();
        $resultItems = DB::table('external_result_items')->join('topics', 'topics.id', '=', 'external_result_items.topic_id')
            ->whereIn('external_result_id', $results->pluck('id'))
            ->get(['external_result_id', 'topics.name as topic', 'external_result_items.source_group', 'earned_marks', 'max_marks'])
            ->groupBy('external_result_id');

        $recommendations = DB::table('recommendations')->join('topics', 'topics.id', '=', 'recommendations.topic_id')
            ->where('learner_id', $user->id)->orderBy('recommendations.id')
            ->get(['recommendations.id', 'topics.name as topic', 'state', 'priority', 'primary_reason', 'recommendations.created_at']);
        $events = DB::table('recommendation_events')->where('learner_id', $user->id)->orderBy('id')
            ->get(['recommendation_id', 'from_state', 'to_state', 'actor', 'created_at'])->groupBy('recommendation_id');

        return [
            'exported_at' => now()->toIso8601String(),
            'account' => ['name' => $user->name, 'email' => $user->email, 'role' => $user->role, 'created_at' => $user->created_at?->toIso8601String()],
            'canvas_connection' => $connection === null ? null : [
                'canvas_user_id' => $connection->canvas_user_id, 'connected_at' => $connection->connected_at, 'disconnected_at' => $connection->revoked_at,
            ],
            'current_topic_results' => $this->access->coursesFor($user)
                ->filter(fn ($course) => $this->access->isStudent($user, $course))
                ->map(fn ($course) => ['course' => $course->name, 'topics' => array_map(
                    fn ($t) => array_intersect_key($t, array_flip(['topic_name', 'label', 'performance_display', 'earned_marks', 'max_marks', 'confidence', 'last_assessed_at'])),
                    $this->performance->forLearnerCourse($user, $course),
                )])->values()->all(),
            'goals_and_help_requests' => DB::table('learner_topic_goals')->join('topics', 'topics.id', '=', 'learner_topic_goals.topic_id')
                ->where('learner_id', $user->id)->orderBy('learner_topic_goals.id')
                ->get(['topics.name as topic', 'goal_type', 'learner_topic_goals.created_at', 'removed_at', 'acknowledged_at'])->all(),
            'quiz_attempts' => $attempts->map(fn ($a) => [
                'quiz' => $a->title, 'attempt_number' => $a->attempt_number, 'status' => $a->status,
                'started_at' => $a->started_at, 'submitted_at' => $a->submitted_at,
                'answers' => $answers->where('attempt_id', $a->id)->map(fn ($ans) => [
                    'question' => $ans->question_text_snapshot,
                    'options' => json_decode((string) $ans->options_snapshot, true),
                    'your_response' => json_decode((string) $ans->response, true),
                    'marks' => ($marks[$ans->id] ?? collect())->values()->all(),
                ])->values()->all(),
            ])->all(),
            'canvas_and_klenam_results' => $results->map(fn ($r) => [
                'canvas_item' => $r->external_item_ref, 'version' => $r->version, 'posted' => (bool) $r->posted, 'excused' => (bool) $r->excused,
                'submitted_at' => $r->submitted_at, 'graded_at' => $r->graded_at, 'synced_at' => $r->synced_at,
                'items' => ($resultItems[$r->id] ?? collect())->values()->all(),
            ])->all(),
            'recommendations' => $recommendations->map(fn ($r) => (array) $r + ['history' => ($events[$r->id] ?? collect())->values()->all()])->all(),
        ];
    }

    /**
     * Deletes the account and everything LearnLoop stored for it, in an order the database's
     * restrictions allow. Canvas itself is not touched.
     */
    public function delete(User $user): void
    {
        DB::transaction(function () use ($user) {
            $recommendationIds = DB::table('recommendations')->where('learner_id', $user->id)->pluck('id');
            DB::table('recommendation_events')->whereIn('recommendation_id', $recommendationIds)->orWhere('learner_id', $user->id)->delete();
            DB::table('recommendations')->whereIn('superseded_by_id', $recommendationIds)->update(['superseded_by_id' => null]);
            DB::table('recommendations')->whereIn('id', $recommendationIds)->delete();
            DB::table('learner_topic_goals')->where('learner_id', $user->id)->delete();

            // Attempts cascade to their items, answers and mark versions.
            DB::table('attempts')->where('learner_id', $user->id)->delete();
            DB::table('assessments')->where('owner_learner_id', $user->id)->delete(); // the learner's revision quizzes

            DB::table('external_results')->where('learner_id', $user->id)->delete(); // items cascade
            DB::table('sync_runs')->where('user_id', $user->id)->delete();
            DB::table('canvas_connections')->where('user_id', $user->id)->delete();
            DB::table('personal_access_tokens')->where('tokenable_type', $user->getMorphClass())->where('tokenable_id', $user->id)->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();

            $user->delete();
        });
    }
}
