<?php

namespace App\Services\Adaptive\Lecturer;

use App\Models\CanvasConnection;
use App\Models\Course;
use App\Models\User;
use App\Services\Adaptive\Canvas\CanvasGateway;
use App\Services\Adaptive\CourseAccess;
use App\Services\Adaptive\TopicPerformanceService;

/**
 * Lecturer view of a course: how the class is doing per topic, and one learner's detail
 * (spec §13.2 learner summaries, §15.4). Learners are the course's Canvas students who have
 * connected LearnLoop. Uses stored evidence; it does not trigger Canvas syncs.
 */
final class ClassOverviewService
{
    private const STATUSES = ['needs_help', 'getting_there', 'strong_unconfirmed', 'strong', 'not_enough_evidence'];

    public function __construct(
        private readonly CanvasGateway $gateway,
        private readonly CourseAccess $access,
        private readonly TopicPerformanceService $performance = new TopicPerformanceService(),
    ) {
    }

    /** @return array{topics: list<array<string, mixed>>, learners: list<array<string, mixed>>} */
    public function forCourse(User $lecturer, Course $course): array
    {
        $learners = $this->learners($lecturer, $course);

        $topics = [];
        $rows = [];
        foreach ($learners as $learner) {
            $results = $this->performance->forLearnerCourse($learner, $course);
            $cells = [];
            foreach ($results as $r) {
                $topics[$r['topic_id']] ??= ['topic_id' => $r['topic_id'], 'topic_name' => $r['topic_name'], 'counts' => array_fill_keys(self::STATUSES, 0)];
                $topics[$r['topic_id']]['counts'][$r['status']]++;
                $cells[] = [
                    'topic_id' => $r['topic_id'],
                    'status' => $r['status'],
                    'performance_display' => $r['performance_display'],
                    'confidence' => $r['confidence'],
                    'conflict' => $r['conflict'] !== null,
                    'pending_count' => $r['pending_count'],
                ];
            }
            $rows[] = ['learner' => ['id' => $learner->id, 'name' => $learner->name], 'topics' => $cells];
        }

        if ($topics === []) {
            foreach ($course->topics()->where('status', 'active')->orderBy('id')->get() as $topic) {
                $topics[$topic->id] = ['topic_id' => $topic->id, 'topic_name' => $topic->name, 'counts' => array_fill_keys(self::STATUSES, 0)];
            }
        }

        return ['topics' => array_values($topics), 'learners' => $rows];
    }

    /** One learner's full topic results, for a lecturer of the course (404 otherwise). */
    public function learnerDetail(User $lecturer, Course $course, int $learnerId): array
    {
        $learner = collect($this->learners($lecturer, $course))->firstWhere('id', $learnerId);
        if ($learner === null) {
            throw LecturerException::notFound();
        }

        return [
            'learner' => ['id' => $learner->id, 'name' => $learner->name],
            'topics' => $this->performance->forLearnerCourse($learner, $course),
        ];
    }

    /** @return list<User> */
    private function learners(User $lecturer, Course $course): array
    {
        if (! $this->access->isLecturer($lecturer, $course)) {
            throw LecturerException::notFound();
        }

        $canvasCourseId = $course->canvasLink?->canvas_course_id;
        $connection = CanvasConnection::where('user_id', $lecturer->id)->whereNull('revoked_at')->first();
        if ($canvasCourseId === null || $connection === null) {
            return [];
        }

        $canvasIds = $this->gateway->courseStudents($canvasCourseId, $connection->canvas_user_id);

        return User::whereIn('id', CanvasConnection::whereIn('canvas_user_id', $canvasIds)->whereNull('revoked_at')->pluck('user_id'))
            ->orderBy('name')->get()->all();
    }
}
