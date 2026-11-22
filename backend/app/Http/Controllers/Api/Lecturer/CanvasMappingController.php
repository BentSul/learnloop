<?php

namespace App\Http\Controllers\Api\Lecturer;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Services\Adaptive\Lecturer\CanvasMappingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** AGENTS.md §12: Canvas item → topic mappings. Validation and the change log live in the service. */
class CanvasMappingController extends Controller
{
    public function __construct(private readonly CanvasMappingService $mappings)
    {
    }

    public function items(Request $request, Course $course): JsonResponse
    {
        return response()->json($this->mappings->items($request->user(), $course));
    }

    public function save(Request $request, Course $course): JsonResponse
    {
        $body = $request->validate(['mappings' => ['present', 'array']]);

        return response()->json($this->mappings->save($request->user(), $course, $body['mappings']));
    }
}
