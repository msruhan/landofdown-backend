<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RankingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RankingController extends Controller
{
    public function __construct(
        private readonly RankingService $rankingService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'sort_by' => 'nullable|in:wins,win_rate,mvp_count,avg_rating,total_kills,total_assists,least_deaths',
            'role_id' => 'nullable|exists:roles,id',
            'hero_id' => 'nullable|exists:heroes,id',
            'min_matches' => 'nullable|integer|min:1',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        return response()->json($this->rankingService->getRankings($request));
    }
}
