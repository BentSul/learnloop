<?php

namespace App\Http\Controllers\Api\Lecturer;

use App\Http\Controllers\Controller;
use App\Services\Adaptive\Canvas\CourseLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Lecturers add the Canvas courses they teach to LearnLoop (AGENTS.md §14). */
class CanvasCourseController extends Controller
{
    public function __construct(private readonly CourseLinkService $links)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->links->teachingCourses($request->user())]);
    }

    public function store(Request $request): JsonResponse
    {
        $body = $request->validate([
            'canvas_course_id' => ['required'],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'code' => ['sometimes', 'nullable', 'string', 'max:50'],
        ]);

        return response()->json($this->links->link(
            $request->user(), (string) $body['canvas_course_id'], ['name' => $body['name'] ?? null, 'code' => $body['code'] ?? null],
        ), 201);
    }
}
