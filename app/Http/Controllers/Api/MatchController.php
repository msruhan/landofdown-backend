<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DraftPick;
use App\Models\GameMatch;
use App\Models\MatchPlayer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class MatchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = GameMatch::with([
            'matchPlayers.player',
            'matchPlayers.hero',
            'matchPlayers.role',
            'patch',
        ]);

        if ($dateFrom = $request->get('date_from')) {
            $query->where('match_date', '>=', $dateFrom);
        }

        if ($dateTo = $request->get('date_to')) {
            $query->where('match_date', '<=', $dateTo);
        }

        if ($playerId = $request->get('player_id')) {
            $query->whereHas('matchPlayers', fn ($q) => $q->where('player_id', $playerId));
        }

        $matches = $query->orderByDesc('match_date')
            ->orderByDesc('id')
            ->paginate($request->get('per_page', 15));

        return response()->json($matches);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'match_date' => 'required|date',
            'duration' => 'nullable|string|max:10',
            'team_a_name' => 'nullable|string|max:255',
            'team_b_name' => 'nullable|string|max:255',
            'winner' => 'required|in:team_a,team_b',
            'patch_id' => 'nullable|integer|exists:patches,id',
            'notes' => 'nullable|string',
            'screenshot_path' => 'nullable|string|max:500',
            'draft_picks' => 'nullable|array',
            'draft_picks.*.team' => 'required_with:draft_picks|in:team_a,team_b',
            'draft_picks.*.action' => 'required_with:draft_picks|in:pick,ban',
            'draft_picks.*.order_index' => 'required_with:draft_picks|integer|min:1|max:20',
            'draft_picks.*.hero_id' => 'nullable|integer|exists:heroes,id',
            'players' => 'required|array|size:10',
            'players.*.player_id' => 'required|exists:players,id',
            'players.*.team' => 'required|in:team_a,team_b',
            'players.*.hero_id' => 'required|exists:heroes,id',
            'players.*.role_id' => 'required|exists:roles,id',
            'players.*.kills' => 'required|integer|min:0',
            'players.*.deaths' => 'required|integer|min:0',
            'players.*.assists' => 'required|integer|min:0',
            'players.*.rating' => 'nullable|numeric|min:0|max:20',
            'players.*.medal' => 'nullable|in:mvp_win,mvp_lose,gold,silver,bronze',
        ]);

        $teamACounts = collect($validated['players'])->where('team', 'team_a')->count();
        $teamBCounts = collect($validated['players'])->where('team', 'team_b')->count();

        if ($teamACounts !== 5 || $teamBCounts !== 5) {
            return response()->json(['message' => 'Each team must have exactly 5 players'], 422);
        }

        $match = DB::transaction(function () use ($validated, $request) {
            $match = GameMatch::create([
                'match_date' => $validated['match_date'],
                'duration' => $validated['duration'] ?? null,
                'team_a_name' => $validated['team_a_name'] ?? 'Team A',
                'team_b_name' => $validated['team_b_name'] ?? 'Team B',
                'winner' => $validated['winner'],
                'patch_id' => $validated['patch_id'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'screenshot_path' => $validated['screenshot_path'] ?? null,
                'created_by' => $request->user()?->id,
            ]);

            foreach ($validated['players'] as $playerData) {
                $result = ($playerData['team'] === $validated['winner']) ? 'win' : 'lose';

                MatchPlayer::create([
                    'match_id' => $match->id,
                    'player_id' => $playerData['player_id'],
                    'team' => $playerData['team'],
                    'hero_id' => $playerData['hero_id'],
                    'role_id' => $playerData['role_id'],
                    'kills' => $playerData['kills'],
                    'deaths' => $playerData['deaths'],
                    'assists' => $playerData['assists'],
                    'rating' => $playerData['rating'] ?? null,
                    'medal' => $playerData['medal'] ?? null,
                    'result' => $result,
                ]);
            }

            foreach ($validated['draft_picks'] ?? [] as $pick) {
                DraftPick::create([
                    'match_id' => $match->id,
                    'team' => $pick['team'],
                    'action' => $pick['action'],
                    'order_index' => $pick['order_index'],
                    'hero_id' => $pick['hero_id'] ?? null,
                ]);
            }

            return $match;
        });

        $match->load([
            'matchPlayers.player',
            'matchPlayers.hero',
            'matchPlayers.role',
            'draftPicks.hero',
            'patch',
        ]);

        return response()->json($match, 201);
    }

    public function show(int $id): JsonResponse
    {
        $match = GameMatch::with([
            'matchPlayers.player',
            'matchPlayers.hero',
            'matchPlayers.role',
            'draftPicks.hero',
            'patch',
            'creator',
        ])->findOrFail($id);

        return response()->json($match);
    }

    public function screenshot(int $id): Response
    {
        $match = GameMatch::findOrFail($id);
        $path = $match->screenshot_path;

        if (!$path) {
            abort(404, 'Screenshot not found');
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return redirect()->away($path);
        }

        $disk = Storage::disk('public');
        if (!$disk->exists($path)) {
            abort(404, 'Screenshot file not found');
        }

        return response()->file($disk->path($path), [
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $match = GameMatch::findOrFail($id);

        $validated = $request->validate([
            'match_date' => 'sometimes|required|date',
            'duration' => 'nullable|string|max:10',
            'team_a_name' => 'nullable|string|max:255',
            'team_b_name' => 'nullable|string|max:255',
            'winner' => 'sometimes|required|in:team_a,team_b',
            'patch_id' => 'nullable|integer|exists:patches,id',
            'notes' => 'nullable|string',
            'screenshot_path' => 'nullable|string|max:500',
            'draft_picks' => 'nullable|array',
            'draft_picks.*.team' => 'required_with:draft_picks|in:team_a,team_b',
            'draft_picks.*.action' => 'required_with:draft_picks|in:pick,ban',
            'draft_picks.*.order_index' => 'required_with:draft_picks|integer|min:1|max:20',
            'draft_picks.*.hero_id' => 'nullable|integer|exists:heroes,id',
            'players' => 'sometimes|required|array|size:10',
            'players.*.player_id' => 'required_with:players|exists:players,id',
            'players.*.team' => 'required_with:players|in:team_a,team_b',
            'players.*.hero_id' => 'required_with:players|exists:heroes,id',
            'players.*.role_id' => 'required_with:players|exists:roles,id',
            'players.*.kills' => 'required_with:players|integer|min:0',
            'players.*.deaths' => 'required_with:players|integer|min:0',
            'players.*.assists' => 'required_with:players|integer|min:0',
            'players.*.rating' => 'nullable|numeric|min:0|max:20',
            'players.*.medal' => 'nullable|in:mvp_win,mvp_lose,gold,silver,bronze',
        ]);

        DB::transaction(function () use ($match, $validated) {
            $matchData = collect($validated)->except(['players', 'draft_picks'])->toArray();
            $match->update($matchData);

            if (array_key_exists('draft_picks', $validated)) {
                $match->draftPicks()->delete();
                foreach ($validated['draft_picks'] ?? [] as $pick) {
                    DraftPick::create([
                        'match_id' => $match->id,
                        'team' => $pick['team'],
                        'action' => $pick['action'],
                        'order_index' => $pick['order_index'],
                        'hero_id' => $pick['hero_id'] ?? null,
                    ]);
                }
            }

            if (isset($validated['players'])) {
                $winner = $validated['winner'] ?? $match->winner;
                $match->matchPlayers()->delete();

                foreach ($validated['players'] as $playerData) {
                    $result = ($playerData['team'] === $winner) ? 'win' : 'lose';

                    MatchPlayer::create([
                        'match_id' => $match->id,
                        'player_id' => $playerData['player_id'],
                        'team' => $playerData['team'],
                        'hero_id' => $playerData['hero_id'],
                        'role_id' => $playerData['role_id'],
                        'kills' => $playerData['kills'],
                        'deaths' => $playerData['deaths'],
                        'assists' => $playerData['assists'],
                        'rating' => $playerData['rating'] ?? null,
                        'medal' => $playerData['medal'] ?? null,
                        'result' => $result,
                    ]);
                }
            }
        });

        $match->load([
            'matchPlayers.player',
            'matchPlayers.hero',
            'matchPlayers.role',
            'draftPicks.hero',
            'patch',
        ]);

        return response()->json($match);
    }

    public function destroy(int $id): JsonResponse
    {
        $match = GameMatch::findOrFail($id);
        $match->delete();

        return response()->json(['message' => 'Match deleted successfully']);
    }
}
