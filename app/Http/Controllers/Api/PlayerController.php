<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Player;
use App\Services\StatisticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlayerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Player::query();

        if ($search = $request->get('search')) {
            $query->where('username', 'like', "%{$search}%");
        }

        $players = $query->orderBy('username')->paginate($request->get('per_page', 15));

        return response()->json($players);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'username' => 'required|string|max:255|unique:players,username',
            'avatar_url' => 'nullable|string|max:500',
        ]);

        $player = Player::create($validated);

        return response()->json($player, 201);
    }

    public function show(int $id, StatisticsService $statsService): JsonResponse
    {
        $player = Player::findOrFail($id);
        $stats = $statsService->playerStats($id);

        return response()->json($stats);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $player = Player::findOrFail($id);

        $validated = $request->validate([
            'username' => 'required|string|max:255|unique:players,username,'.$player->id,
            'avatar_url' => 'nullable|string|max:500',
        ]);

        $player->update($validated);

        return response()->json($player);
    }

    public function destroy(int $id): JsonResponse
    {
        $player = Player::findOrFail($id);
        $player->delete();

        return response()->json(['message' => 'Player deleted successfully']);
    }
}
