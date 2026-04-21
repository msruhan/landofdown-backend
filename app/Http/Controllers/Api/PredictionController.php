<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PredictionReasoningService;
use App\Services\WinPredictionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PredictionController extends Controller
{
    public function __construct(
        private readonly WinPredictionService $service,
        private readonly PredictionReasoningService $reasoningService,
    ) {}

    public function predict(Request $request): JsonResponse
    {
        $data = $request->validate([
            'team_a' => 'required|array|min:1|max:5',
            'team_a.*.player_id' => 'required|integer|exists:players,id',
            'team_a.*.hero_id' => 'nullable|integer|exists:heroes,id',
            'team_a.*.role_id' => 'nullable|integer|exists:roles,id',
            'team_b' => 'required|array|min:1|max:5',
            'team_b.*.player_id' => 'required|integer|exists:players,id',
            'team_b.*.hero_id' => 'nullable|integer|exists:heroes,id',
            'team_b.*.role_id' => 'nullable|integer|exists:roles,id',
        ]);

        return response()->json($this->service->predict($data['team_a'], $data['team_b']));
    }

    public function reasoning(Request $request): JsonResponse
    {
        $data = $request->validate([
            'team_a' => 'required|array',
            'team_a.name' => 'nullable|string|max:120',
            'team_a.players' => 'required|array|min:1|max:5',
            'team_a.players.*.player_id' => 'required|integer|exists:players,id',
            'team_a.players.*.hero_id' => 'nullable|integer|exists:heroes,id',
            'team_a.players.*.role_id' => 'nullable|integer|exists:roles,id',

            'team_b' => 'required|array',
            'team_b.name' => 'nullable|string|max:120',
            'team_b.players' => 'required|array|min:1|max:5',
            'team_b.players.*.player_id' => 'required|integer|exists:players,id',
            'team_b.players.*.hero_id' => 'nullable|integer|exists:heroes,id',
            'team_b.players.*.role_id' => 'nullable|integer|exists:roles,id',

            'prediction' => 'nullable|array',
        ]);

        $result = $this->reasoningService->generate(
            $data['team_a'],
            $data['team_b'],
            $data['prediction'] ?? null,
        );

        return response()->json($result);
    }
}
