<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DraftAnalysisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DraftController extends Controller
{
    public function __construct(private readonly DraftAnalysisService $service) {}

    public function overview(Request $request): JsonResponse
    {
        $patchId = $request->integer('patch_id') ?: null;

        return response()->json($this->service->overview($patchId));
    }

    public function synergy(Request $request): JsonResponse
    {
        $patchId = $request->integer('patch_id') ?: null;
        $limit = (int) $request->get('limit', 20);

        return response()->json([
            'data' => $this->service->heroPairSynergy($patchId, $limit),
        ]);
    }

    public function counters(Request $request): JsonResponse
    {
        $patchId = $request->integer('patch_id') ?: null;
        $limit = (int) $request->get('limit', 20);

        return response()->json([
            'data' => $this->service->topCounters($patchId, $limit),
        ]);
    }

    public function recommend(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ally_hero_ids' => 'array',
            'ally_hero_ids.*' => 'integer|exists:heroes,id',
            'enemy_hero_ids' => 'array',
            'enemy_hero_ids.*' => 'integer|exists:heroes,id',
            'limit' => 'integer|min:1|max:50',
        ]);

        return response()->json([
            'data' => $this->service->recommend(
                $validated['ally_hero_ids'] ?? [],
                $validated['enemy_hero_ids'] ?? [],
                (int) ($validated['limit'] ?? 10),
            ),
        ]);
    }

    public function matchDraft(int $matchId): JsonResponse
    {
        return response()->json($this->service->matchDraft($matchId));
    }
}
