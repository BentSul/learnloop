<?php

namespace App\Http\Controllers\Api\Lecturer;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Topic;
use App\Services\Adaptive\Lecturer\QuestionBankService;
use App\Services\Adaptive\Lecturer\TopicService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Lecturers create, rename and archive the topics of their course. */
class TopicController extends Controller
{
    public function __construct(
        private readonly TopicService $topics,
        private readonly QuestionBankService $bank,
    ) {
    }

    public function store(Request $request, Course $course): JsonResponse
    {
        $topic = $this->topics->create($request->user(), $course, $request->all());

        return response()->json($this->present($request, $topic), 201);
    }

    public function update(Request $request, Topic $topic): JsonResponse
    {
        return response()->json($this->present($request, $this->topics->update($request->user(), $topic, $request->all())));
    }

    /** @return array<string, mixed> same shape as GET /api/courses/{course}/topics items */
    private function present(Request $request, Topic $topic): array
    {
        return [
            'id' => $topic->id,
            'course_id' => $topic->course_id,
            'name' => $topic->name,
            'description' => $topic->description,
            'learning_outcomes' => $topic->learning_outcomes,
            'status' => $topic->status,
        ] + $this->bank->topicSummary($request->user(), $topic);
    }
}
