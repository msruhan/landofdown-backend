<?php

namespace App\Services;

use App\Models\Patch;
use Illuminate\Support\Facades\DB;

class MetaTrackerService
{
    public function listPatches(): array
    {
        return Patch::query()
            ->leftJoin('game_matches', 'patches.id', '=', 'game_matches.patch_id')
            ->select('patches.*')
            ->selectRaw('COUNT(game_matches.id) as match_count')
            ->groupBy('patches.id')
            ->orderByDesc('patches.release_date')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'version' => $p->version,
                'name' => $p->name,
                'release_date' => $p->release_date?->format('Y-m-d'),
                'notes' => $p->notes,
                'match_count' => (int) $p->match_count,
            ])
            ->toArray();
    }

    public function overview(?int $patchId): array
    {
        $patches = Patch::orderByDesc('release_date')->get();

        if (! $patchId) {
            $patchId = $patches->first()?->id;
        }

        if (! $patchId) {
            return [
                'patch' => null,
                'patches' => [],
                'hero_performance' => [],
            ];
        }

        $current = $patches->firstWhere('id', $patchId);
        $previous = $patches->where('release_date', '<', $current?->release_date)
            ->sortByDesc('release_date')
            ->first();

        return [
            'current_patch' => $this->patchSummary($current),
            'previous_patch' => $previous ? $this->patchSummary($previous) : null,
            'hero_performance' => $this->compareHeroes($current?->id, $previous?->id),
            'role_performance' => $this->compareRoles($current?->id, $previous?->id),
            'player_performance' => $this->comparePlayers($current?->id, $previous?->id),
        ];
    }

    public function compareHeroes(?int $currentId, ?int $previousId): array
    {
        $currentStats = $this->heroStats($currentId);
        $previousStats = $this->heroStats($previousId);
        $previousByHero = collect($previousStats)->keyBy('hero_id');

        return collect($currentStats)
            ->map(function ($row) use ($previousByHero) {
                $prev = $previousByHero->get($row['hero_id']);
                $prevRate = $prev['win_rate'] ?? null;
                $prevPicks = $prev['picks'] ?? 0;

                return array_merge($row, [
                    'previous_win_rate' => $prevRate,
                    'delta_win_rate' => $prevRate !== null ? round($row['win_rate'] - $prevRate, 1) : null,
                    'previous_picks' => $prevPicks,
                    'delta_picks' => $row['picks'] - $prevPicks,
                ]);
            })
            ->sortByDesc(fn ($r) => abs($r['delta_win_rate'] ?? 0) + ($r['picks'] / 100))
            ->take(20)
            ->values()
            ->toArray();
    }

    private function heroStats(?int $patchId): array
    {
        if (! $patchId) {
            return [];
        }

        return DB::table('match_players')
            ->join('game_matches', 'match_players.match_id', '=', 'game_matches.id')
            ->join('heroes', 'match_players.hero_id', '=', 'heroes.id')
            ->where('game_matches.patch_id', $patchId)
            ->select('heroes.id as hero_id', 'heroes.name as hero_name', 'heroes.icon_url')
            ->selectRaw('COUNT(*) as picks')
            ->selectRaw("SUM(CASE WHEN match_players.result = 'win' THEN 1 ELSE 0 END) as wins")
            ->groupBy('heroes.id', 'heroes.name', 'heroes.icon_url')
            ->get()
            ->map(fn ($h) => [
                'hero_id' => (int) $h->hero_id,
                'hero_name' => $h->hero_name,
                'icon_url' => $h->icon_url,
                'picks' => (int) $h->picks,
                'wins' => (int) $h->wins,
                'win_rate' => $h->picks > 0 ? round(($h->wins / $h->picks) * 100, 1) : 0,
            ])
            ->toArray();
    }

    public function compareRoles(?int $currentId, ?int $previousId): array
    {
        $current = $this->roleStats($currentId);
        $previous = collect($this->roleStats($previousId))->keyBy('role_id');

        return collect($current)->map(function ($row) use ($previous) {
            $prev = $previous->get($row['role_id']);

            return array_merge($row, [
                'previous_win_rate' => $prev['win_rate'] ?? null,
                'delta_win_rate' => $prev ? round($row['win_rate'] - $prev['win_rate'], 1) : null,
            ]);
        })->toArray();
    }

    private function roleStats(?int $patchId): array
    {
        if (! $patchId) {
            return [];
        }

        return DB::table('match_players')
            ->join('game_matches', 'match_players.match_id', '=', 'game_matches.id')
            ->join('roles', 'match_players.role_id', '=', 'roles.id')
            ->where('game_matches.patch_id', $patchId)
            ->select('roles.id as role_id', 'roles.name as role_name')
            ->selectRaw('COUNT(*) as usage')
            ->selectRaw("SUM(CASE WHEN match_players.result = 'win' THEN 1 ELSE 0 END) as wins")
            ->groupBy('roles.id', 'roles.name')
            ->get()
            ->map(fn ($r) => [
                'role_id' => (int) $r->role_id,
                'role_name' => $r->role_name,
                'usage' => (int) $r->usage,
                'win_rate' => $r->usage > 0 ? round(($r->wins / $r->usage) * 100, 1) : 0,
            ])
            ->toArray();
    }

    public function comparePlayers(?int $currentId, ?int $previousId): array
    {
        $current = $this->playerStats($currentId);
        $previous = collect($this->playerStats($previousId))->keyBy('player_id');

        return collect($current)
            ->map(function ($row) use ($previous) {
                $prev = $previous->get($row['player_id']);
                $prevRate = $prev['win_rate'] ?? null;
                $prevRating = $prev['avg_rating'] ?? null;

                return array_merge($row, [
                    'previous_win_rate' => $prevRate,
                    'delta_win_rate' => $prevRate !== null ? round($row['win_rate'] - $prevRate, 1) : null,
                    'previous_avg_rating' => $prevRating,
                    'delta_avg_rating' => $prevRating !== null && $row['avg_rating'] !== null
                        ? round($row['avg_rating'] - $prevRating, 2)
                        : null,
                ]);
            })
            ->sortByDesc(fn ($r) => abs($r['delta_win_rate'] ?? 0))
            ->values()
            ->toArray();
    }

    private function playerStats(?int $patchId): array
    {
        if (! $patchId) {
            return [];
        }

        return DB::table('match_players')
            ->join('game_matches', 'match_players.match_id', '=', 'game_matches.id')
            ->join('players', 'match_players.player_id', '=', 'players.id')
            ->where('game_matches.patch_id', $patchId)
            ->select('players.id as player_id', 'players.username', 'players.avatar_url')
            ->selectRaw('COUNT(*) as matches_played')
            ->selectRaw("SUM(CASE WHEN match_players.result = 'win' THEN 1 ELSE 0 END) as wins")
            ->selectRaw('AVG(match_players.rating) as avg_rating')
            ->groupBy('players.id', 'players.username', 'players.avatar_url')
            ->get()
            ->map(fn ($p) => [
                'player_id' => (int) $p->player_id,
                'username' => $p->username,
                'avatar_url' => $p->avatar_url,
                'matches_played' => (int) $p->matches_played,
                'wins' => (int) $p->wins,
                'win_rate' => $p->matches_played > 0 ? round(($p->wins / $p->matches_played) * 100, 1) : 0,
                'avg_rating' => $p->avg_rating !== null ? round((float) $p->avg_rating, 2) : null,
            ])
            ->toArray();
    }

    private function patchSummary(?Patch $patch): ?array
    {
        if (! $patch) {
            return null;
        }

        return [
            'id' => $patch->id,
            'version' => $patch->version,
            'name' => $patch->name,
            'release_date' => $patch->release_date?->format('Y-m-d'),
            'notes' => $patch->notes,
        ];
    }
}
