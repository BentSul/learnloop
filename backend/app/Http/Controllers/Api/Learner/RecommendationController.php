<?php

namespace App\Http\Controllers\Api\Learner;

use App\Http\Controllers\Controller;
use App\Services\Adaptive\Recommendations\RecommendationActions;
use App\Services\Adaptive\Recommendations\RecommendationPresenter;
use App\Services\Adaptive\Recommendations\RecommendationQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** AGENTS.md §11: recommendation sections and learner actions. State changes go through the services only. */
class RecommendationController extends Controller
{
    private const SECTIONS = ['active', 'accepted', 'postponed', 'dismissed', 'history'];

    public function __construct(
        private readonly RecommendationQuery $query,
        private readonly RecommendationActions $actions,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate(['state' => ['sometimes', Rule::in(self::SECTIONS)]]);

        return response()->json(['data' => $this->query->section($request->user(), $request->query('state', 'active'))]);
    }

    public function shortlist(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->query->shortlist($request->user())]);
    }

    public function accept(Request $request, int $recommendation): JsonResponse
    {
        return response()->json(RecommendationPresenter::toArray($this->actions->accept($request->user(), $recommendation)));
    }

    public function postpone(Request $request, int $recommendation): JsonResponse
    {
        $body = $request->validate(['reminder_at' => ['required', 'string']]);

        return response()->json(RecommendationPresenter::toArray(
            $this->actions->postpone($request->user(), $recommendation, $body['reminder_at'])
        ));
    }

    public function dismiss(Request $request, int $recommendation): JsonResponse
    {
        return response()->json(RecommendationPresenter::toArray($this->actions->dismiss($request->user(), $recommendation)));
    }

    public function restore(Request $request, int $recommendation): JsonResponse
    {
        $result = $this->actions->restore($request->user(), $recommendation);

        return response()->json([
            'data' => RecommendationPresenter::toArray($result['recommendation']),
            'restored' => $result['restored'],
            'message' => $result['message'],
        ]);
    }
}
