<?php

namespace App\Http\Controllers\Api\Lecturer;

use App\Http\Controllers\Controller;
use App\Models\Topic;
use App\Services\Adaptive\Lecturer\QuizApiService;
use App\Services\Adaptive\Lecturer\YouTubeSuggestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Optional outside sources for lecturers: YouTube video suggestions and QuizAPI questions (AGENTS.md §15). */
class LearningSourcesController extends Controller
{
    public function __construct(
        private readonly YouTubeSuggestionService $youtube,
        private readonly QuizApiService $quizApi,
    ) {
    }

    /** Which optional features are switched on, so the UI shows only working buttons. */
    public function status(): JsonResponse
    {
        return response()->json([
            'video_suggestions' => $this->youtube->enabled(),
            'question_search' => $this->quizApi->enabled(),
        ]);
    }

    public function suggestVideos(Request $request, Topic $topic): JsonResponse
    {
        return response()->json(['data' => $this->youtube->suggest($request->user(), $topic)]);
    }

    public function searchQuestions(Request $request, Topic $topic): JsonResponse
    {
        $filters = $request->validate([
            'tags' => ['sometimes', 'nullable', 'string', 'max:200'],
            'category' => ['sometimes', 'nullable', 'string', 'max:100'],
            'difficulty' => ['sometimes', 'nullable', 'in:easy,medium,hard'],
            'limit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:20'],
        ]);

        return response()->json(['data' => $this->quizApi->search($request->user(), $topic, $filters)]);
    }

    public function importQuestions(Request $request, Topic $topic): JsonResponse
    {
        $body = $request->validate([
            'questions' => ['required', 'array', 'min:1', 'max:50'],
            'questions.*' => ['array'],
        ]);

        return response()->json($this->quizApi->import($request->user(), $topic, $body['questions']));
    }
}
