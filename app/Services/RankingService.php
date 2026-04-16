<?php

namespace App\Services;

use App\Models\Player;
use Illuminate\Http\Request;

class RankingService
{
    public function getRankings(Request $request): array
    {
        $sortBy = $request->get('sort_by', 'wins');
        $roleId = $request->get('role_id');
        $heroId = $request->get('hero_id');
        $minMatches = $request->get('min_matches', 1);
        $dateFrom = $request->get('date_from');
        $dateTo = $request->get('date_to');
        $perPage = $request->get('per_page', 20);

        $query = Player::select('players.*')
            ->join('match_players', 'players.id', '=', 'match_players.player_id')
            ->join('game_matches', 'match_players.match_id', '=', 'game_matches.id')
            ->selectRaw('COUNT(DISTINCT match_players.id) as total_matches')
            ->selectRaw('SUM(CASE WHEN match_players.result = \'win\' THEN 1 ELSE 0 END) as wins')
            ->selectRaw('SUM(CASE WHEN match_players.result = \'lose\' THEN 1 ELSE 0 END) as losses')
            ->selectRaw('SUM(CASE WHEN match_players.medal IN (\'mvp\', \'mvp_win\', \'mvp_lose\') THEN 1 ELSE 0 END) as mvp_count')
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
            $query->orderByRaw('SUM(CASE WHEN match_players.result = \'win\' THEN 1 ELSE 0 END) / COUNT(DISTINCT match_players.id) DESC');
        } else {
            $query->orderBy($sortColumn, $sortDirection);
        }

        $paginated = $query->paginate($perPage);

        $items = collect($paginated->items())->map(function ($player) {
            $totalMatches = (int) $player->total_matches;
            $wins = (int) $player->wins;
            $totalKills = (int) $player->total_kills;
            $totalDeaths = (int) $player->total_deaths;
            $totalAssists = (int) $player->total_assists;

            return [
                'id' => $player->id,
                'username' => $player->username,
                'avatar_url' => $player->avatar_url,
                'total_matches' => $totalMatches,
                'wins' => $wins,
                'losses' => (int) $player->losses,
                'win_rate' => $totalMatches > 0 ? round(($wins / $totalMatches) * 100, 1) : 0,
                'mvp_count' => (int) $player->mvp_count,
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
