<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hero;
use App\Services\StatisticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HeroController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Hero::with('role');

        if ($roleId = $request->get('role_id')) {
            $query->where('role_id', $roleId);
        }

        $heroes = $query->orderBy('name')->get();

        return response()->json($heroes);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:heroes,name',
            'hero_role' => 'nullable|string|max:100',
            'lane' => 'nullable|in:jungle,exp,gold,mid,roam',
            'role_id' => 'nullable|exists:roles,id',
            'icon_url' => 'nullable|string|max:500',
        ]);

        $hero = Hero::create($validated);
        $hero->load('role');

        return response()->json($hero, 201);
    }

    public function show(int $id, StatisticsService $statsService): JsonResponse
    {
        $hero = Hero::with('role')->findOrFail($id);
        $heroStats = collect($statsService->heroStats())->firstWhere('id', $id);

        return response()->json([
            'hero' => $hero,
            'stats' => $heroStats,
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $hero = Hero::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:heroes,name,'.$hero->id,
            'hero_role' => 'nullable|string|max:100',
            'lane' => 'nullable|in:jungle,exp,gold,mid,roam',
            'role_id' => 'nullable|exists:roles,id',
            'icon_url' => 'nullable|string|max:500',
        ]);

        $hero->update($validated);
        $hero->load('role');

        return response()->json($hero);
    }

    public function destroy(int $id): JsonResponse
    {
        $hero = Hero::findOrFail($id);
        $hero->delete();

        return response()->json(['message' => 'Hero deleted successfully']);
    }
}
