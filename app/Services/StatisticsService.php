<?php

namespace App\Services;

use App\Models\GameMatch;
use App\Models\Hero;
use App\Models\MatchPlayer;
use App\Models\Player;
use App\Models\Role;
use Illuminate\Support\Facades\DB;

class StatisticsService
{
    public function dashboard(): array
    {
        $totalMatches = GameMatch::count();
        $totalPlayers = Player::count();
        $totalHeroes = MatchPlayer::distinct('hero_id')->count('hero_id');
        $totalMvps = MatchPlayer::whereIn('medal', ['mvp', 'mvp_win', 'mvp_lose'])->count();

        $wins = MatchPlayer::where('result', 'win')->count();
        $total = MatchPlayer::count();
        $globalWinRate = $total > 0 ? round(($wins / $total) * 100, 1) : 0;

        $topWinners = $this->buildLeaderboard('wins');
        $topMvps = $this->buildLeaderboard('mvp_count');
        $seasonMvp = $this->buildSeasonMvp();

        $winTrend = GameMatch::orderByDesc('match_date')
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (GameMatch $m) => [
                'label' => $m->match_date->format('M d'),
                'value' => $m->winner === 'team_a' ? 1 : 0,
                'result' => $m->winner === 'team_a' ? 'win' : 'loss',
            ]);

        $recentMatches = GameMatch::with(['matchPlayers.player', 'matchPlayers.hero'])
            ->orderByDesc('match_date')
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        $mostUsedHeroes = Hero::select('heroes.name as hero_name')
            ->selectRaw('COUNT(match_players.id) as count')
            ->selectRaw('ROUND(SUM(CASE WHEN match_players.result = \'win\' THEN 1 ELSE 0 END) * 100.0 / COUNT(match_players.id), 1) as win_rate')
            ->join('match_players', 'heroes.id', '=', 'match_players.hero_id')
            ->groupBy('heroes.id', 'heroes.name')
            ->orderByDesc('count')
            ->limit(10)
            ->get()
            ->toArray();

        $mostPlayedRoles = Role::select('roles.name as role_name')
            ->selectRaw('COUNT(match_players.id) as count')
            ->selectRaw('ROUND(SUM(CASE WHEN match_players.result = \'win\' THEN 1 ELSE 0 END) * 100.0 / COUNT(match_players.id), 1) as win_rate')
            ->join('match_players', 'roles.id', '=', 'match_players.role_id')
            ->groupBy('roles.id', 'roles.name')
            ->orderByDesc('count')
            ->limit(5)
            ->get()
            ->toArray();

        return [
            'total_matches' => $totalMatches,
            'total_players' => $totalPlayers,
            'total_heroes' => $totalHeroes,
            'total_mvps' => $totalMvps,
            'global_win_rate' => $globalWinRate,
            'top_winners' => $topWinners,
            'top_mvps' => $topMvps,
            'season_mvp' => $seasonMvp,
            'win_trend' => $winTrend,
            'recent_matches' => $recentMatches,
            'most_used_heroes' => $mostUsedHeroes,
            'most_played_roles' => $mostPlayedRoles,
        ];
    }

    /**
     * Per-player aggregates for leaderboard / season MVP (players with ≥1 match row).
     */
    private function leaderboardStatsQuery()
    {
        return Player::select('players.*')
            ->selectRaw('COUNT(DISTINCT match_players.id) as matches_played')
            ->selectRaw('SUM(CASE WHEN match_players.result = \'win\' THEN 1 ELSE 0 END) as wins')
            ->selectRaw('SUM(CASE WHEN match_players.result = \'lose\' THEN 1 ELSE 0 END) as losses')
            ->selectRaw('SUM(CASE WHEN match_players.medal IN (\'mvp\', \'mvp_win\', \'mvp_lose\') THEN 1 ELSE 0 END) as mvp_count')
            ->selectRaw('AVG(match_players.rating) as avg_rating')
            ->selectRaw('AVG(match_players.kills) as avg_kills')
            ->selectRaw('AVG(match_players.deaths) as avg_deaths')
            ->selectRaw('AVG(match_players.assists) as avg_assists')
            ->join('match_players', 'players.id', '=', 'match_players.player_id')
            ->groupBy('players.id');
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Model  $p  Player row with aggregated columns
     * @return array<string, mixed>
     */
    private function mapLeaderboardEntry($p): array
    {
        $matchesPlayed = (int) $p->matches_played;
        $wins = (int) $p->wins;

        return [
            'player_id' => $p->id,
            'username' => $p->username,
            'matches_played' => $matchesPlayed,
            'wins' => $wins,
            'losses' => (int) $p->losses,
            'win_rate' => $matchesPlayed > 0 ? round(($wins / $matchesPlayed) * 100, 1) : 0,
            'mvp_count' => (int) $p->mvp_count,
            'avg_rating' => round((float) $p->avg_rating, 1),
            'avg_kills' => round((float) $p->avg_kills, 1),
            'avg_deaths' => round((float) $p->avg_deaths, 1),
            'avg_assists' => round((float) $p->avg_assists, 1),
        ];
    }

    private function buildLeaderboard(string $sortBy): array
    {
        $query = $this->leaderboardStatsQuery();
        $query->orderByDesc($sortBy === 'mvp_count' ? 'mvp_count' : 'wins');

        return $query->limit(5)->get()->map(fn ($p) => $this->mapLeaderboardEntry($p))->toArray();
    }

    /**
     * MVP of the season: among players with the highest win count, pick the best average rating
     * (then MVP count, then win rate as tie-breakers).
     */
    private function buildSeasonMvp(): ?array
    {
        $rows = $this->leaderboardStatsQuery()->get();
        if ($rows->isEmpty()) {
            return null;
        }

        $maxWins = (int) $rows->max(fn ($p) => (int) $p->wins);
        $candidates = $rows->filter(fn ($p) => (int) $p->wins === $maxWins);

        $best = $candidates->sort(function ($a, $b) {
            $ratingCmp = (float) $b->avg_rating <=> (float) $a->avg_rating;
            if ($ratingCmp !== 0) {
                return $ratingCmp;
            }

            $mvpCmp = (int) $b->mvp_count <=> (int) $a->mvp_count;
            if ($mvpCmp !== 0) {
                return $mvpCmp;
            }

            $wrA = (int) $a->matches_played > 0 ? (int) $a->wins / (int) $a->matches_played : 0.0;
            $wrB = (int) $b->matches_played > 0 ? (int) $b->wins / (int) $b->matches_played : 0.0;

            return $wrB <=> $wrA;
        })->first();

        return $best ? $this->mapLeaderboardEntry($best) : null;
    }

    public function playerStats(int $playerId): array
    {
        $player = Player::findOrFail($playerId);

        $matchPlayers = MatchPlayer::where('player_id', $playerId);

        $totalMatches = (clone $matchPlayers)->count();
        $totalWins = (clone $matchPlayers)->where('result', 'win')->count();
        $totalLosses = $totalMatches - $totalWins;

        $medals = [
            'mvp' => (clone $matchPlayers)->whereIn('medal', ['mvp', 'mvp_win', 'mvp_lose'])->count(),
            'mvp_win' => (clone $matchPlayers)->where('medal', 'mvp_win')->count(),
            'mvp_lose' => (clone $matchPlayers)->where('medal', 'mvp_lose')->count(),
            'gold' => (clone $matchPlayers)->where('medal', 'gold')->count(),
            'silver' => (clone $matchPlayers)->where('medal', 'silver')->count(),
            'bronze' => (clone $matchPlayers)->where('medal', 'bronze')->count(),
        ];

        $avgRating = (clone $matchPlayers)->avg('rating');
        $totalKills = (clone $matchPlayers)->sum('kills');
        $totalDeaths = (clone $matchPlayers)->sum('deaths');
        $totalAssists = (clone $matchPlayers)->sum('assists');

        $avgKills = $totalMatches > 0 ? round($totalKills / $totalMatches, 1) : 0;
        $avgDeaths = $totalMatches > 0 ? round($totalDeaths / $totalMatches, 1) : 0;
        $avgAssists = $totalMatches > 0 ? round($totalAssists / $totalMatches, 1) : 0;

        $rolesPlayed = MatchPlayer::where('player_id', $playerId)
            ->select('role_id')
            ->selectRaw('COUNT(*) as times_played')
            ->selectRaw('SUM(CASE WHEN result = \'win\' THEN 1 ELSE 0 END) as wins')
            ->groupBy('role_id')
            ->with('role:id,name')
            ->get()
            ->map(fn ($rp) => [
                'role' => $rp->role,
                'times_played' => (int) $rp->times_played,
                'wins' => (int) $rp->wins,
                'win_rate' => $rp->times_played > 0 ? round(($rp->wins / $rp->times_played) * 100, 1) : 0,
            ]);

        $heroesUsed = MatchPlayer::where('player_id', $playerId)
            ->select('hero_id')
            ->selectRaw('COUNT(*) as times_played')
            ->selectRaw('SUM(CASE WHEN result = \'win\' THEN 1 ELSE 0 END) as wins')
            ->selectRaw('AVG(rating) as avg_rating')
            ->groupBy('hero_id')
            ->with('hero:id,name,icon_url')
            ->get()
            ->map(fn ($hu) => [
                'hero' => $hu->hero,
                'times_played' => (int) $hu->times_played,
                'wins' => (int) $hu->wins,
                'win_rate' => $hu->times_played > 0 ? round(($hu->wins / $hu->times_played) * 100, 1) : 0,
                'avg_rating' => round((float) $hu->avg_rating, 1),
            ]);

        $recentPerformance = MatchPlayer::where('player_id', $playerId)
            ->with(['gameMatch:id,match_date', 'hero:id,name', 'role:id,name'])
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn ($mp) => [
                'match_id' => $mp->match_id,
                'match_date' => $mp->gameMatch?->match_date?->format('Y-m-d'),
                'hero' => $mp->hero?->name,
                'role' => $mp->role?->name,
                'kills' => $mp->kills,
                'deaths' => $mp->deaths,
                'assists' => $mp->assists,
                'rating' => $mp->rating,
                'medal' => $mp->medal,
                'result' => $mp->result,
            ]);

        $ratingTrend = MatchPlayer::where('player_id', $playerId)
            ->whereNotNull('rating')
            ->orderBy('id')
            ->select('match_id', 'rating')
            ->limit(50)
            ->get();

        // Win/lose streak
        $latestResults = MatchPlayer::where('player_id', $playerId)
            ->orderByDesc('id')
            ->pluck('result');

        $streak = 0;
        $streakType = null;
        foreach ($latestResults as $result) {
            if ($streakType === null) {
                $streakType = $result;
                $streak = 1;
            } elseif ($result === $streakType) {
                $streak++;
            } else {
                break;
            }
        }

        $mvpRate = $totalMatches > 0 ? round(($medals['mvp'] / $totalMatches) * 100, 1) : 0;
        $heroPoolDiversity = $heroesUsed->count();

        $firstMatch = MatchPlayer::where('player_id', $playerId)
            ->join('game_matches', 'match_players.match_id', '=', 'game_matches.id')
            ->orderBy('game_matches.match_date')
            ->select('game_matches.match_date')
            ->first();

        $latestMatch = MatchPlayer::where('player_id', $playerId)
            ->join('game_matches', 'match_players.match_id', '=', 'game_matches.id')
            ->orderByDesc('game_matches.match_date')
            ->select('game_matches.match_date')
            ->first();

        return [
            'player' => $player,
            'total_matches' => $totalMatches,
            'total_wins' => $totalWins,
            'total_losses' => $totalLosses,
            'win_rate' => $totalMatches > 0 ? round(($totalWins / $totalMatches) * 100, 1) : 0,
            'medals' => $medals,
            'avg_rating' => $avgRating ? round((float) $avgRating, 1) : null,
            'total_kda' => [
                'kills' => (int) $totalKills,
                'deaths' => (int) $totalDeaths,
                'assists' => (int) $totalAssists,
            ],
            'avg_kda' => [
                'kills' => $avgKills,
                'deaths' => $avgDeaths,
                'assists' => $avgAssists,
            ],
            'roles_played' => $rolesPlayed,
            'heroes_used' => $heroesUsed,
            'recent_performance' => $recentPerformance,
            'rating_trend' => $ratingTrend,
            'current_streak' => [
                'type' => $streakType,
                'count' => $streak,
            ],
            'mvp_rate' => $mvpRate,
            'hero_pool_diversity' => $heroPoolDiversity,
            'first_match_date' => $firstMatch?->match_date,
            'latest_match_date' => $latestMatch?->match_date,
        ];
    }

    public function heroStats(): array
    {
        return Hero::select('heroes.*')
            ->selectRaw('COUNT(match_players.id) as usage_count')
            ->selectRaw('SUM(CASE WHEN match_players.result = \'win\' THEN 1 ELSE 0 END) as wins')
            ->selectRaw('AVG(match_players.rating) as avg_rating')
            ->selectRaw('SUM(CASE WHEN match_players.medal IN (\'mvp\', \'mvp_win\', \'mvp_lose\') THEN 1 ELSE 0 END) as mvp_count')
            ->leftJoin('match_players', 'heroes.id', '=', 'match_players.hero_id')
            ->groupBy('heroes.id')
            ->orderByDesc('usage_count')
            ->get()
            ->map(function ($hero) {
                $bestPlayer = MatchPlayer::where('hero_id', $hero->id)
                    ->select('player_id')
                    ->selectRaw('AVG(rating) as avg_rating')
                    ->selectRaw('COUNT(*) as times_played')
                    ->groupBy('player_id')
                    ->having('times_played', '>=', 1)
                    ->orderByDesc('avg_rating')
                    ->with('player:id,username')
                    ->first();

                $topPlayers = MatchPlayer::where('hero_id', $hero->id)
                    ->select('player_id')
                    ->selectRaw('COUNT(*) as matches')
                    ->selectRaw('AVG(rating) as avg_rating')
                    ->groupBy('player_id')
                    ->orderByDesc('matches')
                    ->orderByDesc('avg_rating')
                    ->limit(5)
                    ->with('player:id,username')
                    ->get()
                    ->map(fn ($tp) => [
                        'id' => $tp->player_id,
                        'username' => $tp->player?->username,
                        'matches' => (int) $tp->matches,
                        'avg_rating' => round((float) $tp->avg_rating, 1),
                    ])
                    ->toArray();

                return [
                    'id' => $hero->id,
                    'name' => $hero->name,
                    'icon_url' => $hero->icon_url,
                    'role_id' => $hero->role_id,
                    'usage_count' => (int) $hero->usage_count,
                    'wins' => (int) $hero->wins,
                    'win_rate' => $hero->usage_count > 0 ? round(($hero->wins / $hero->usage_count) * 100, 1) : 0,
                    'avg_rating' => $hero->avg_rating ? round((float) $hero->avg_rating, 1) : null,
                    'mvp_count' => (int) $hero->mvp_count,
                    'best_player' => $bestPlayer ? [
                        'id' => $bestPlayer->player_id,
                        'username' => $bestPlayer->player?->username,
                        'avg_rating' => round((float) $bestPlayer->avg_rating, 1),
                    ] : null,
                    'top_players' => $topPlayers,
                ];
            })
            ->toArray();
    }

    public function roleStats(): array
    {
        return Role::select('roles.*')
            ->selectRaw('COUNT(match_players.id) as usage_count')
            ->selectRaw('SUM(CASE WHEN match_players.result = \'win\' THEN 1 ELSE 0 END) as wins')
            ->selectRaw('AVG(match_players.rating) as avg_rating')
            ->leftJoin('match_players', 'roles.id', '=', 'match_players.role_id')
            ->groupBy('roles.id')
            ->orderByDesc('usage_count')
            ->get()
            ->map(function ($role) {
                $mostUsedPlayer = MatchPlayer::where('role_id', $role->id)
                    ->select('player_id')
                    ->selectRaw('COUNT(*) as matches')
                    ->groupBy('player_id')
                    ->orderByDesc('matches')
                    ->with('player:id,username')
                    ->first();

                $mostWinningPlayer = MatchPlayer::where('role_id', $role->id)
                    ->select('player_id')
                    ->selectRaw('SUM(CASE WHEN result = \'win\' THEN 1 ELSE 0 END) as wins')
                    ->selectRaw('COUNT(*) as matches')
                    ->groupBy('player_id')
                    ->orderByDesc('wins')
                    ->orderByDesc('matches')
                    ->with('player:id,username')
                    ->first();

                $topUsedPlayers = MatchPlayer::where('role_id', $role->id)
                    ->select('player_id')
                    ->selectRaw('COUNT(*) as matches')
                    ->selectRaw('SUM(CASE WHEN result = \'win\' THEN 1 ELSE 0 END) as wins')
                    ->groupBy('player_id')
                    ->orderByDesc('matches')
                    ->orderByDesc('wins')
                    ->limit(5)
                    ->with('player:id,username')
                    ->get()
                    ->map(fn ($rp) => [
                        'id' => $rp->player_id,
                        'username' => $rp->player?->username,
                        'matches' => (int) $rp->matches,
                        'wins' => (int) $rp->wins,
                        'win_rate' => $rp->matches > 0 ? round(((int) $rp->wins / (int) $rp->matches) * 100, 1) : 0,
                    ])
                    ->toArray();

                $topWinningPlayers = MatchPlayer::where('role_id', $role->id)
                    ->select('player_id')
                    ->selectRaw('SUM(CASE WHEN result = \'win\' THEN 1 ELSE 0 END) as wins')
                    ->selectRaw('COUNT(*) as matches')
                    ->groupBy('player_id')
                    ->orderByDesc('wins')
                    ->orderByDesc('matches')
                    ->limit(5)
                    ->with('player:id,username')
                    ->get()
                    ->map(fn ($rp) => [
                        'id' => $rp->player_id,
                        'username' => $rp->player?->username,
                        'wins' => (int) $rp->wins,
                        'matches' => (int) $rp->matches,
                        'win_rate' => $rp->matches > 0 ? round(((int) $rp->wins / (int) $rp->matches) * 100, 1) : 0,
                    ])
                    ->toArray();

                $bestPlayers = MatchPlayer::where('role_id', $role->id)
                    ->select('player_id')
                    ->selectRaw('AVG(rating) as avg_rating')
                    ->selectRaw('COUNT(*) as times_played')
                    ->groupBy('player_id')
                    ->having('times_played', '>=', 1)
                    ->orderByDesc('avg_rating')
                    ->limit(3)
                    ->with('player:id,username')
                    ->get()
                    ->map(fn ($bp) => [
                        'id' => $bp->player_id,
                        'username' => $bp->player?->username,
                        'avg_rating' => round((float) $bp->avg_rating, 1),
                    ]);

                return [
                    'id' => $role->id,
                    'name' => $role->name,
                    'icon_url' => $role->icon_url,
                    'usage_count' => (int) $role->usage_count,
                    'wins' => (int) $role->wins,
                    'win_rate' => $role->usage_count > 0 ? round(($role->wins / $role->usage_count) * 100, 1) : 0,
                    'avg_rating' => $role->avg_rating ? round((float) $role->avg_rating, 1) : null,
                    'most_used_player' => $mostUsedPlayer ? [
                        'id' => $mostUsedPlayer->player_id,
                        'username' => $mostUsedPlayer->player?->username,
                        'matches' => (int) $mostUsedPlayer->matches,
                    ] : null,
                    'most_winning_player' => $mostWinningPlayer ? [
                        'id' => $mostWinningPlayer->player_id,
                        'username' => $mostWinningPlayer->player?->username,
                        'wins' => (int) $mostWinningPlayer->wins,
                        'matches' => (int) $mostWinningPlayer->matches,
                        'win_rate' => $mostWinningPlayer->matches > 0
                            ? round(((int) $mostWinningPlayer->wins / (int) $mostWinningPlayer->matches) * 100, 1)
                            : 0,
                    ] : null,
                    'top_used_players' => $topUsedPlayers,
                    'top_winning_players' => $topWinningPlayers,
                    'best_players' => $bestPlayers,
                ];
            })
            ->toArray();
    }

    public function trends(): array
    {
        $winRateTrend = GameMatch::selectRaw('match_date')
            ->selectRaw('COUNT(*) as matches_count')
            ->groupBy('match_date')
            ->orderBy('match_date')
            ->limit(30)
            ->get()
            ->map(function ($day) {
                $dayPlayers = MatchPlayer::whereHas('gameMatch', fn ($q) => $q->where('match_date', $day->match_date));
                $total = (clone $dayPlayers)->count();
                $wins = (clone $dayPlayers)->where('result', 'win')->count();

                return [
                    'date' => $day->match_date,
                    'matches' => (int) $day->matches_count,
                    'win_rate' => $total > 0 ? round(($wins / $total) * 100, 1) : 0,
                ];
            });

        $popularHeroes = DB::table('match_players')
            ->join('game_matches', 'match_players.match_id', '=', 'game_matches.id')
            ->join('heroes', 'match_players.hero_id', '=', 'heroes.id')
            ->select('heroes.name as hero_name', 'game_matches.match_date')
            ->selectRaw('COUNT(*) as usage')
            ->groupBy('heroes.name', 'game_matches.match_date')
            ->orderBy('game_matches.match_date')
            ->get()
            ->groupBy('hero_name')
            ->map(fn ($entries, $heroName) => [
                'hero' => $heroName,
                'trend' => $entries->map(fn ($e) => [
                    'date' => $e->match_date,
                    'usage' => (int) $e->usage,
                ])->values(),
            ])
            ->values();

        return [
            'win_rate_trend' => $winRateTrend,
            'popular_heroes' => $popularHeroes,
        ];
    }
}
