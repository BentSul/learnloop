<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Adaptive\Canvas\CanvasOAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** "Sign in with Canvas" and "Connect with Canvas" (AGENTS.md §14). */
class CanvasOAuthController extends Controller
{
    public function __construct(private readonly CanvasOAuthService $oauth)
    {
    }

    /** Public: whether the login page should show "Sign in with Canvas". */
    public function status(): JsonResponse
    {
        return response()->json(['enabled' => $this->oauth->enabled()]);
    }

    /** Public: start signing in (or signing up) with Canvas. */
    public function startLogin(): JsonResponse
    {
        return response()->json(['authorize_url' => $this->oauth->start('login')]);
    }

    /** Signed in: link Canvas to this account. */
    public function startConnect(Request $request): JsonResponse
    {
        return response()->json(['authorize_url' => $this->oauth->start('connect', $request->user())]);
    }

    /** Canvas sends the browser here after the user approves (or cancels). */
    public function callback(Request $request): RedirectResponse
    {
        return redirect($this->oauth->complete(
            $request->query('code'), $request->query('state'), $request->query('error'),
        ));
    }
}
