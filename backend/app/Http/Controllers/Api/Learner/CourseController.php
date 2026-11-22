<?php

namespace App\Http\Controllers\Api\Learner;

use App\Enums\Adaptive\AssessmentType;
use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\Course;
use App\Services\Adaptive\Canvas\CanvasNotConnected;
use App\Services\Adaptive\Canvas\CanvasSyncService;
use App\Services\Adaptive\CourseAccess;
use App\Services\Adaptive\Recommendations\RecommendationEngine;
use App\Services\Adaptive\TopicPerformanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** AGENTS.md §10: courses, topic performance and Canvas sync. */
class CourseController extends Controller
{
    use ChecksLearnerAccess;

    public function index(Request $request, CourseAccess $access): JsonResponse
    {
        return response()->json(
            $access->coursesFor($request->user())
                ->map(fn (Course $c) => ['id' => $c->id, 'name' => $c->name, 'code' => $c->code])
                ->values()
        );
    }

    public function topics(Request $request, Course $course, CanvasSyncService $sync, TopicPerformanceService $performance): JsonResponse
    {
        $user = $request->user();
        $this->assertStudent($user, $course);

        if ($sync->needsSync($user, $course)) {
            try {
                $sync->syncLearnerCourse($user, $course);
            } catch (CanvasNotConnected) {
                // Show LearnLoop evidence only; the learner can still sync manually later.
            }
        }

        return response()->json(['data' => $performance->forLearnerCourse($user, $course)]);
    }

    public function sync(Request $request, Course $course, CanvasSyncService $sync): JsonResponse
    {
        $this->assertStudent($request->user(), $course);

        return response()->json($sync->syncLearnerCourse($request->user(), $course)->toArray());
    }

    public function practice(Request $request, Course $course): JsonResponse
    {
        $this->assertStudent($request->user(), $course);

        $assessments = Assessment::with(['topic', 'items.question.topic'])
            ->where('course_id', $course->id)
            ->where('assessment_type', AssessmentType::Practice->value)
            ->where('status', 'open')
            ->orderBy('id')->get();

        return response()->json($assessments->map(function (Assessment $a) {
            // Practice sets may not store a topic; use the single topic of their questions if there is one.
            $topic = $a->topic;
            if ($topic === null) {
                $topics = $a->items->map(fn ($i) => $i->question->topic)->unique('id');
                $topic = $topics->count() === 1 ? $topics->first() : null;
            }

            return [
                'id' => $a->id,
                'title' => $a->title,
                'topic' => $topic ? ['id' => $topic->id, 'name' => $topic->name] : null,
            ];
        })->values());
    }

    public function diagnosticPrompt(Request $request, Course $course, RecommendationEngine $engine): JsonResponse
    {
        $this->assertStudent($request->user(), $course);

        return response()->json(['show' => $engine->showDiagnosticPrompt($request->user(), $course)]);
    }
}
