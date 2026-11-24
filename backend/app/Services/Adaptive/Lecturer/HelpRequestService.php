<?php

namespace App\Services\Adaptive\Lecturer;

use App\Enums\Adaptive\GoalType;
use App\Models\Course;
use App\Models\LearnerTopicGoal;
use App\Models\User;
use App\Services\Adaptive\CourseAccess;
use App\Services\Adaptive\TopicPerformanceService;

/**
 * Lecturers see which students asked for help in which topics of their course, with each
 * student's current result in that topic, and can mark a request as seen.
 */
class HelpRequestService
{
    public function __construct(
        private readonly CourseAccess $access,
        private readonly TopicPerformanceService $performance,
    ) {
    }

    /** @return list<array<string, mixed>> active requests, oldest first */
    public function forCourse(User $lecturer, Course $course): array
    {
        $this->assertLecturer($lecturer, $course);

        $requests = LearnerTopicGoal::with(['learner', 'topic'])
            ->where('goal_type', GoalType::Help->value)->whereNull('removed_at')
            ->whereHas('topic', fn ($q) => $q->where('course_id', $course->id)->where('status', 'active'))
            ->orderBy('created_at')->orderBy('id')->get();

        // One performance calculation per student, not per request.
        $results = [];
        foreach ($requests->pluck('learner')->unique('id') as $learner) {
            $results[$learner->id] = collect($this->performance->forLearnerCourse($learner, $course))->keyBy('topic_id');
        }

        return $requests->map(function (LearnerTopicGoal $goal) use ($results) {
            $result = $results[$goal->learner_id][$goal->topic_id] ?? null;

            return [
                'id' => $goal->id,
                'learner' => ['id' => $goal->learner->id, 'name' => $goal->learner->name, 'email' => $goal->learner->email],
                'topic' => ['id' => $goal->topic->id, 'name' => $goal->topic->name],
                'requested_at' => $goal->created_at?->toIso8601String(),
                'acknowledged_at' => $goal->acknowledged_at?->toIso8601String(),
                'status' => $result['status'] ?? null,
                'label' => $result['label'] ?? null,
                'performance_display' => $result['performance_display'] ?? null,
            ];
        })->values()->all();
    }

    public function acknowledge(User $lecturer, LearnerTopicGoal $goal): LearnerTopicGoal
    {
        if ($goal->goal_type !== GoalType::Help || $goal->removed_at !== null) {
            throw LecturerException::notFound();
        }
        $this->assertLecturer($lecturer, $goal->topic->course);

        if ($goal->acknowledged_at === null) {
            $goal->update(['acknowledged_at' => now(), 'acknowledged_by' => $lecturer->id]);
        }

        return $goal;
    }

    private function assertLecturer(User $lecturer, Course $course): void
    {
        if (! $this->access->isLecturer($lecturer, $course)) {
            throw LecturerException::notFound();
        }
    }
}
