<?php

namespace App\Services;

use App\Models\Player;
use App\Models\MatchPlayer;
use App\Models\GameMatch;
use Illuminate\Http\Request;

class RankingService
{
    public function getRankings(Request $request): array
    {
        $sortBy = $request->get('sort_by', 'wins');
        $roleId = $request->get('role_id');
        $heroId = $request->get('hero_id');
        $minMatches = $request->get('min_matches', 0);
        $dateFrom = $request->get('date_from');
        $dateTo = $request->get('date_to');
        $patchId = $request->get('patch_id');
        $perPage = $request->get('per_page', 20);

        $query = Player::select('players.*')
            ->leftJoin('match_players', 'players.id', '=', 'match_players.player_id')
            ->leftJoin('game_matches', 'match_players.match_id', '=', 'game_matches.id')
            ->selectRaw('COUNT(DISTINCT match_players.id) as total_matches')
            ->selectRaw('SUM(CASE WHEN match_players.result = \'win\' THEN 1 ELSE 0 END) as wins')
            ->selectRaw('SUM(CASE WHEN match_players.result = \'lose\' THEN 1 ELSE 0 END) as losses')
            ->selectRaw('SUM(CASE WHEN match_players.medal IN (\'mvp\', \'mvp_win\', \'mvp_lose\') THEN 1 ELSE 0 END) as mvp_count')
            ->selectRaw('SUM(CASE WHEN match_players.medal = \'mvp_win\' OR (match_players.medal = \'mvp\' AND match_players.result = \'win\') THEN 1 ELSE 0 END) as mvp_win_count')
            ->selectRaw('SUM(CASE WHEN match_players.medal = \'mvp_lose\' OR (match_players.medal = \'mvp\' AND match_players.result = \'lose\') THEN 1 ELSE 0 END) as mvp_lose_count')
            ->selectRaw('AVG(match_players.rating) as avg_rating')
            ->selectRaw('SUM(match_players.kills) as total_kills')
            ->selectRaw('SUM(match_players.deaths) as total_deaths')
            ->selectRaw('SUM(match_players.assists) as total_assists')
            ->groupBy('players.id');

        if ($roleId) {
            $query->where('match_players.role_id', $roleId);
        }

        if ($heroId) {
            $query->where('match_players.hero_id', $heroId);
        }

        if ($dateFrom) {
            $query->where('game_matches.match_date', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->where('game_matches.match_date', '<=', $dateTo);
        }

        if ($patchId) {
            $query->where('game_matches.patch_id', $patchId);
        }

        $query->havingRaw('COUNT(DISTINCT match_players.id) >= ?', [$minMatches]);

        $sortColumn = match ($sortBy) {
            'win_rate' => 'wins',
            'mvp_count' => 'mvp_count',
            'avg_rating' => 'avg_rating',
            'total_kills' => 'total_kills',
            'total_assists' => 'total_assists',
            'least_deaths' => 'total_deaths',
            default => 'wins',
        };

        $sortDirection = $sortBy === 'least_deaths' ? 'asc' : 'desc';

        if ($sortBy === 'win_rate') {
            $query->orderByRaw('COALESCE(SUM(CASE WHEN match_players.result = \'win\' THEN 1 ELSE 0 END) / NULLIF(COUNT(DISTINCT match_players.id), 0), 0) DESC');
            $query->orderByDesc('wins');
            $query->orderByDesc('avg_rating');
            $query->orderBy('players.username');
        } else {
            $query->orderBy($sortColumn, $sortDirection);
            $query->orderByDesc('wins');
            $query->orderByDesc('avg_rating');
            $query->orderBy('players.username');
        }

        $latestMatchId = GameMatch::query()
            ->join('match_players', 'game_matches.id', '=', 'match_players.match_id')
            ->when($roleId, fn ($q) => $q->where('match_players.role_id', $roleId))
            ->when($heroId, fn ($q) => $q->where('match_players.hero_id', $heroId))
            ->when($dateFrom, fn ($q) => $q->where('game_matches.match_date', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->where('game_matches.match_date', '<=', $dateTo))
            ->when($patchId, fn ($q) => $q->where('game_matches.patch_id', $patchId))
            ->orderByDesc('game_matches.match_date')
            ->orderByDesc('game_matches.id')
            ->value('game_matches.id');

        $previousRankByPlayer = [];
        if ($latestMatchId) {
            $previousQuery = Player::select('players.id')
                ->leftJoin('match_players', 'players.id', '=', 'match_players.player_id')
                ->leftJoin('game_matches', 'match_players.match_id', '=', 'game_matches.id')
                ->selectRaw('COUNT(DISTINCT match_players.id) as total_matches')
                ->selectRaw('SUM(CASE WHEN match_players.result = \'win\' THEN 1 ELSE 0 END) as wins')
                ->selectRaw('AVG(match_players.rating) as avg_rating')
                ->groupBy('players.id')
                ->where(function ($q) use ($latestMatchId) {
                    $q->whereNull('match_players.match_id')
                        ->orWhere('match_players.match_id', '!=', $latestMatchId);
                });

            if ($roleId) {
                $previousQuery->where('match_players.role_id', $roleId);
            }
            if ($heroId) {
                $previousQuery->where('match_players.hero_id', $heroId);
            }
            if ($dateFrom) {
                $previousQuery->where('game_matches.match_date', '>=', $dateFrom);
            }
            if ($dateTo) {
                $previousQuery->where('game_matches.match_date', '<=', $dateTo);
            }
            if ($patchId) {
                $previousQuery->where('game_matches.patch_id', $patchId);
            }

            $previousQuery->havingRaw('COUNT(DISTINCT match_players.id) >= ?', [$minMatches]);

            if ($sortBy === 'win_rate') {
                $previousQuery->orderByRaw('COALESCE(SUM(CASE WHEN match_players.result = \'win\' THEN 1 ELSE 0 END) / NULLIF(COUNT(DISTINCT match_players.id), 0), 0) DESC');
                $previousQuery->orderByDesc('wins');
                $previousQuery->orderByDesc('avg_rating');
                $previousQuery->orderBy('players.username');
            } else {
                $previousQuery->orderBy($sortColumn, $sortDirection);
                $previousQuery->orderByDesc('wins');
                $previousQuery->orderByDesc('avg_rating');
                $previousQuery->orderBy('players.username');
            }

            $previousOrderedIds = $previousQuery->pluck('players.id')->values();
            foreach ($previousOrderedIds as $idx => $pid) {
                $previousRankByPlayer[(int) $pid] = $idx + 1;
            }
        }

        $paginated = $query->paginate($perPage);

        $items = collect($paginated->items())->map(function ($player) use ($roleId, $heroId, $dateFrom, $dateTo, $patchId, $previousRankByPlayer) {
            $totalMatches = (int) $player->total_matches;
            $wins = (int) $player->wins;
            $totalKills = (int) $player->total_kills;
            $totalDeaths = (int) $player->total_deaths;
            $totalAssists = (int) $player->total_assists;
            $playerMatches = MatchPlayer::where('player_id', $player->id)
                ->when($roleId, fn ($q) => $q->where('role_id', $roleId))
                ->when($heroId, fn ($q) => $q->where('hero_id', $heroId))
                ->when($dateFrom, fn ($q) => $q->whereHas('gameMatch', fn ($qq) => $qq->where('match_date', '>=', $dateFrom)))
                ->when($dateTo, fn ($q) => $q->whereHas('gameMatch', fn ($qq) => $qq->where('match_date', '<=', $dateTo)))
                ->when($patchId, fn ($q) => $q->whereHas('gameMatch', fn ($qq) => $qq->where('patch_id', $patchId)));

            $recentResults = (clone $playerMatches)
                ->orderByDesc('id')
                ->limit(5)
                ->pluck('result')
                ->toArray();

            $recentWins = collect($recentResults)->filter(fn ($r) => $r === 'win')->count();
            $recentRate = count($recentResults) > 0 ? ($recentWins / count($recentResults)) * 100 : 0;
            $formMeter = $recentRate >= 70 ? 'hot' : ($recentRate >= 40 ? 'warm' : 'cold');

            $topHero = (clone $playerMatches)
                ->join('heroes', 'match_players.hero_id', '=', 'heroes.id')
                ->selectRaw('heroes.name as name, heroes.icon_url as icon_url, COUNT(*) as total')
                ->groupBy('match_players.hero_id', 'heroes.name', 'heroes.icon_url')
                ->orderByDesc('total')
                ->orderBy('heroes.name')
                ->first();

            $topRole = (clone $playerMatches)
                ->join('roles', 'match_players.role_id', '=', 'roles.id')
                ->selectRaw('roles.name as name, COUNT(*) as total')
                ->groupBy('match_players.role_id', 'roles.name')
                ->orderByDesc('total')
                ->orderBy('roles.name')
                ->first();

            $previousRank = $previousRankByPlayer[(int) $player->id] ?? null;

            return [
                'id' => $player->id,
                'player_id' => $player->id,
                'username' => $player->username,
                'avatar_url' => $player->avatar_url,
                'previous_rank' => $previousRank,
                'rank_change' => 0,
                'total_matches' => $totalMatches,
                'matches_played' => $totalMatches,
                'wins' => $wins,
                'losses' => (int) $player->losses,
                'win_rate' => $totalMatches > 0 ? round(($wins / $totalMatches) * 100, 1) : 0,
                'mvp_count' => (int) $player->mvp_count,
                'mvp_win_count' => (int) $player->mvp_win_count,
                'mvp_lose_count' => (int) $player->mvp_lose_count,
                'avg_rating' => $player->avg_rating ? round((float) $player->avg_rating, 1) : null,
                'total_kda' => [
                    'kills' => $totalKills,
                    'deaths' => $totalDeaths,
                    'assists' => $totalAssists,
                ],
                'avg_kda' => [
                    'kills' => $totalMatches > 0 ? round($totalKills / $totalMatches, 1) : 0,
                    'deaths' => $totalMatches > 0 ? round($totalDeaths / $totalMatches, 1) : 0,
                    'assists' => $totalMatches > 0 ? round($totalAssists / $totalMatches, 1) : 0,
                ],
                'top_hero' => $topHero?->name,
                'top_hero_icon' => $topHero?->icon_url,
                'top_role' => $topRole?->name,
                'recent_form' => array_map(fn ($r) => $r === 'win' ? 'W' : 'L', $recentResults),
                'form_meter' => $formMeter,
            ];
        });

        return [
            'data' => $items,
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ];
    }
}
