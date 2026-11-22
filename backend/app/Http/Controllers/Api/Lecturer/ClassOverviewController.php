<?php

namespace App\Http\Controllers\Api\Lecturer;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Services\Adaptive\Lecturer\ClassOverviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** AGENTS.md §12: class overview and single-learner detail for the course's lecturer. */
class ClassOverviewController extends Controller
{
    public function __construct(private readonly ClassOverviewService $overview)
    {
    }

    public function show(Request $request, Course $course): JsonResponse
    {
        return response()->json($this->overview->forCourse($request->user(), $course));
    }

    public function learner(Request $request, Course $course, int $learner): JsonResponse
    {
        return response()->json($this->overview->learnerDetail($request->user(), $course, $learner));
    }
}
