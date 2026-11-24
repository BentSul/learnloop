<?php

namespace App\Http\Controllers\Api\Lecturer;

use App\Http\Controllers\Controller;
use App\Models\Topic;
use App\Models\TopicResource;
use App\Services\Adaptive\Lecturer\TopicResourceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Lecturers curate videos, readings and practice links per topic (AGENTS.md §15). */
class TopicResourceController extends Controller
{
    public function __construct(private readonly TopicResourceService $resources)
    {
    }

    public function index(Request $request, Topic $topic): JsonResponse
    {
        return response()->json(['data' => $this->resources->forLecturer($request->user(), $topic)]);
    }

    public function store(Request $request, Topic $topic): JsonResponse
    {
        return response()->json($this->resources->create($request->user(), $topic, $request->all())->toLecturerArray(), 201);
    }

    public function update(Request $request, TopicResource $resource): JsonResponse
    {
        return response()->json($this->resources->update($request->user(), $resource, $request->all())->toLecturerArray());
    }

    public function destroy(Request $request, TopicResource $resource): Response
    {
        $this->resources->delete($request->user(), $resource);

        return response()->noContent();
    }
}
