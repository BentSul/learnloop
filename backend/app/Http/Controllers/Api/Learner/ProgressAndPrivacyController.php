<?php

namespace App\Http\Controllers\Api\Learner;

use App\Http\Controllers\Controller;
use App\Models\Topic;
use App\Services\Adaptive\PrivacyService;
use App\Services\Adaptive\ProgressHistoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** A learner's progress chart and their own data (AGENTS.md §15). */
class ProgressAndPrivacyController extends Controller
{
    public function history(Request $request, Topic $topic, ProgressHistoryService $history): JsonResponse
    {
        return response()->json($history->forLearnerTopic($request->user(), $topic));
    }

    public function export(Request $request, PrivacyService $privacy): JsonResponse
    {
        $filename = 'learnloop-my-data-'.now()->format('Y-m-d').'.json';

        return response()->json($privacy->export($request->user()), 200, [
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function destroy(Request $request, PrivacyService $privacy): Response
    {
        $user = $request->user();
        $body = $request->validate([
            'confirm' => ['required', 'string'],
            'password' => ['sometimes', 'nullable', 'string'],
        ]);

        if ($body['confirm'] !== 'DELETE MY ACCOUNT') {
            throw ValidationException::withMessages(['confirm' => 'Type DELETE MY ACCOUNT to confirm.']);
        }
        if ($user->role === 'admin') {
            throw ValidationException::withMessages(['confirm' => 'Admin accounts cannot be deleted here.']);
        }
        // Accounts signed in with Canvas have no password they know; everyone else confirms with theirs.
        $signedInWithCanvas = $user->currentAccessToken()?->name === 'canvas_login';
        if (! $signedInWithCanvas && ! Hash::check((string) ($body['password'] ?? ''), $user->password)) {
            throw ValidationException::withMessages(['password' => 'Your password is not correct.']);
        }

        $privacy->delete($user);

        return response()->noContent();
    }
}
