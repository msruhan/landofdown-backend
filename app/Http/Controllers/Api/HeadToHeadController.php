<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\HeadToHeadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HeadToHeadController extends Controller
{
    public function __construct(private readonly HeadToHeadService $service) {}

    public function players(Request $request): JsonResponse
    {
        $data = $request->validate([
            'player_a_id' => 'required|integer|exists:players,id',
            'player_b_id' => 'required|integer|exists:players,id|different:player_a_id',
        ]);

        return response()->json($this->service->comparePlayers(
            (int) $data['player_a_id'],
            (int) $data['player_b_id'],
        ));
    }

    public function teams(Request $request): JsonResponse
    {
        $data = $request->validate([
            'team_a' => 'required|array|min:1|max:5',
            'team_a.*' => 'integer|exists:players,id',
            'team_b' => 'required|array|min:1|max:5',
            'team_b.*' => 'integer|exists:players,id',
        ]);

        return response()->json($this->service->compareTeams($data['team_a'], $data['team_b']));
    }
}
