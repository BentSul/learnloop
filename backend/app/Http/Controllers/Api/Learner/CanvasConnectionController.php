<?php

namespace App\Http\Controllers\Api\Learner;

use App\Http\Controllers\Controller;
use App\Services\Adaptive\Canvas\CanvasConnectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Connect, check or remove the user's own Canvas access token (learners and lecturers). */
class CanvasConnectionController extends Controller
{
    public function __construct(private readonly CanvasConnectionService $connections)
    {
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->connections->status($request->user()));
    }

    public function store(Request $request): JsonResponse
    {
        $body = $request->validate(['access_token' => ['required', 'string', 'min:20', 'max:500']]);

        return response()->json($this->connections->connect($request->user(), $body['access_token']));
    }

    public function destroy(Request $request): Response
    {
        $this->connections->disconnect($request->user());

        return response()->noContent();
    }
}
