<?php

namespace App\Http\Controllers\Api\Learner;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Services\Adaptive\Quiz\AttemptService;
use App\Services\Adaptive\Quiz\LearnerAttemptView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AGENTS.md §9: practice quiz attempts. The service throws exceptions that render their
 * own JSON (404/409/422); attempts are always loaded through findForLearner().
 */
class AttemptController extends Controller
{
    use ChecksLearnerAccess;

    public function __construct(private readonly AttemptService $attempts)
    {
    }

    public function start(Request $request, Assessment $assessment): JsonResponse
    {
        $this->assertStudent($request->user(), $assessment->course);
        $attempt = $this->attempts->start($request->user(), $assessment);

        return response()->json(LearnerAttemptView::make($attempt), 201);
    }

    public function show(Request $request, int $attempt): JsonResponse
    {
        return response()->json(LearnerAttemptView::make($this->attempts->findForLearner($request->user(), $attempt)));
    }

    public function saveAnswer(Request $request, int $attempt, int $question): JsonResponse
    {
        $body = $request->validate([
            'option_ids' => ['present', 'array'],
            'option_ids.*' => ['string'],
        ]);

        $model = $this->attempts->findForLearner($request->user(), $attempt);
        $this->attempts->saveAnswer($request->user(), $model, $question, $body);

        return response()->json(['saved' => true]);
    }

    public function submit(Request $request, int $attempt): JsonResponse
    {
        $request->validate(['confirm_unanswered' => ['sometimes', 'boolean']]);

        $model = $this->attempts->findForLearner($request->user(), $attempt);
        $result = $this->attempts->submit($request->user(), $model, $request->boolean('confirm_unanswered'));

        return response()->json($result->toLearnerArray());
    }
}
