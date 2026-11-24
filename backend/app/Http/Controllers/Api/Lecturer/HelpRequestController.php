<?php

namespace App\Http\Controllers\Api\Lecturer;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\LearnerTopicGoal;
use App\Services\Adaptive\Lecturer\HelpRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Students asking for help in a lecturer's course (AGENTS.md §15). */
class HelpRequestController extends Controller
{
    public function __construct(private readonly HelpRequestService $help)
    {
    }

    public function index(Request $request, Course $course): JsonResponse
    {
        return response()->json(['data' => $this->help->forCourse($request->user(), $course)]);
    }

    public function acknowledge(Request $request, LearnerTopicGoal $goal): JsonResponse
    {
        $goal = $this->help->acknowledge($request->user(), $goal);

        return response()->json(['id' => $goal->id, 'acknowledged_at' => $goal->acknowledged_at?->toIso8601String()]);
    }
}
