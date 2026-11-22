<?php

namespace App\Http\Controllers\Api\Learner;

use App\Enums\Adaptive\GoalType;
use App\Http\Controllers\Controller;
use App\Models\LearnerTopicGoal;
use App\Models\Topic;
use App\Services\Adaptive\GoalService;
use App\Services\Adaptive\Quiz\LearnerAttemptView;
use App\Services\Adaptive\Quiz\RevisionQuizService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** AGENTS.md §11: mastery goals, help requests and revision quizzes. */
class GoalController extends Controller
{
    use ChecksLearnerAccess;

    public function __construct(private readonly GoalService $goals)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->goals->forLearner($request->user())
            ->map(fn (LearnerTopicGoal $g) => [
                'id' => $g->id,
                'topic' => ['id' => $g->topic->id, 'name' => $g->topic->name],
                'goal_type' => $g->goal_type->value,
            ])->values());
    }

    public function addGoal(Request $request, Topic $topic): JsonResponse
    {
        return $this->add($request, $topic, GoalType::Mastery);
    }

    public function removeGoal(Request $request, Topic $topic): Response
    {
        return $this->remove($request, $topic, GoalType::Mastery);
    }

    public function addHelp(Request $request, Topic $topic): JsonResponse
    {
        return $this->add($request, $topic, GoalType::Help);
    }

    public function removeHelp(Request $request, Topic $topic): Response
    {
        return $this->remove($request, $topic, GoalType::Help);
    }

    public function revisionQuiz(Request $request, Topic $topic, RevisionQuizService $revision): JsonResponse
    {
        $this->assertStudentTopic($request->user(), $topic);

        return response()->json(LearnerAttemptView::make($revision->createAndStart($request->user(), $topic)), 201);
    }

    private function add(Request $request, Topic $topic, GoalType $type): JsonResponse
    {
        $this->assertStudentTopic($request->user(), $topic);
        $goal = $this->goals->add($request->user(), $topic, $type);

        return response()->json([
            'id' => $goal->id,
            'topic' => ['id' => $topic->id, 'name' => $topic->name],
            'goal_type' => $type->value,
        ], 201);
    }

    private function remove(Request $request, Topic $topic, GoalType $type): Response
    {
        $this->assertStudentTopic($request->user(), $topic);
        $this->goals->remove($request->user(), $topic, $type);

        return response()->noContent();
    }
}
