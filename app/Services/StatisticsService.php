<?php

namespace App\Services;

use App\Models\GameMatch;
use App\Models\Hero;
use App\Models\MatchPlayer;
use App\Models\Player;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StatisticsService
{
    public function dashboard(Request $request): array
    {
        $patchId = $request->get('patch_id');

        $matchQuery = GameMatch::query();
        if ($patchId) {
            $matchQuery->where('patch_id', $patchId);
        }
        $matchIds = $matchQuery->pluck('id');

        $totalMatches = $matchIds->count();
        $totalPlayers = Player::count();
        $totalHeroes = MatchPlayer::when($patchId, fn ($q) => $q->whereIn('match_id', $matchIds))
            ->distinct('hero_id')
            ->count('hero_id');
        $totalMvps = MatchPlayer::when($patchId, fn ($q) => $q->whereIn('match_id', $matchIds))
            ->whereIn('medal', ['mvp', 'mvp_win', 'mvp_lose'])
            ->count();

        $wins = MatchPlayer::when($patchId, fn ($q) => $q->whereIn('match_id', $matchIds))
            ->where('result', 'win')
            ->count();
        $total = MatchPlayer::when($patchId, fn ($q) => $q->whereIn('match_id', $matchIds))->count();
        $globalWinRate = $total > 0 ? round(($wins / $total) * 100, 1) : 0;

        $topWinners = $this->buildLeaderboard('wins', $patchId);
        $topMvps = $this->buildLeaderboard('mvp_count', $patchId);
        $seasonMvp = $this->buildSeasonMvp($patchId);

        $winTrend = GameMatch::when($patchId, fn ($q) => $q->where('patch_id', $patchId))
            ->orderByDesc('match_date')
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
            ->when($patchId, fn ($q) => $q->where('patch_id', $patchId))
            ->orderByDesc('match_date')
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        $mostUsedHeroes = Hero::select('heroes.name as hero_name')
            ->selectRaw('COUNT(match_players.id) as count')
            ->selectRaw('ROUND(SUM(CASE WHEN match_players.result = \'win\' THEN 1 ELSE 0 END) * 100.0 / COUNT(match_players.id), 1) as win_rate')
            ->join('match_players', 'heroes.id', '=', 'match_players.hero_id')
            ->when($patchId, fn ($q) => $q->whereIn('match_players.match_id', $matchIds))
            ->groupBy('heroes.id', 'heroes.name')
            ->orderByDesc('count')
            ->limit(10)
            ->get()
            ->toArray();

        $mostPlayedRoles = Role::select('roles.name as role_name')
            ->selectRaw('COUNT(match_players.id) as count')
            ->selectRaw('ROUND(SUM(CASE WHEN match_players.result = \'win\' THEN 1 ELSE 0 END) * 100.0 / COUNT(match_players.id), 1) as win_rate')
            ->join('match_players', 'roles.id', '=', 'match_players.role_id')
            ->when($patchId, fn ($q) => $q->whereIn('match_players.match_id', $matchIds))
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
    private function leaderboardStatsQuery(?int $patchId = null)
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
            ->join('game_matches', 'match_players.match_id', '=', 'game_matches.id')
            ->when($patchId, fn ($q) => $q->where('game_matches.patch_id', $patchId))
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

    private function buildLeaderboard(string $sortBy, ?int $patchId = null): array
    {
        $query = $this->leaderboardStatsQuery($patchId);
        $query->orderByDesc($sortBy === 'mvp_count' ? 'mvp_count' : 'wins');

        return $query->limit(5)->get()->map(fn ($p) => $this->mapLeaderboardEntry($p))->toArray();
    }

    /**
     * MVP of the season: among players with the highest win count, pick the best average rating
     * (then MVP count, then win rate as tie-breakers).
     */
    private function buildSeasonMvp(?int $patchId = null): ?array
    {
        $rows = $this->leaderboardStatsQuery($patchId)->get();
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

    public function playerStats(int $playerId, ?Request $request = null): array
    {
        $player = Player::findOrFail($playerId);
        $patchId = $request?->get('patch_id');

        $matchPlayers = MatchPlayer::where('player_id', $playerId)
            ->when($patchId, fn ($q) => $q->whereHas('gameMatch', fn ($qq) => $qq->where('patch_id', $patchId)));

        $totalMatches = (clone $matchPlayers)->count();
        $totalWins = (clone $matchPlayers)->where('result', 'win')->count();
        $totalLosses = $totalMatches - $totalWins;

        $medals = [
            'mvp' => (clone $matchPlayers)->whereIn('medal', ['mvp', 'mvp_win', 'mvp_lose'])->count(),
            'mvp_win' => (clone $matchPlayers)->where(function ($q) {
                $q->where('medal', 'mvp_win')
                    ->orWhere(function ($qq) {
                        $qq->where('medal', 'mvp')->where('result', 'win');
                    });
            })->count(),
            'mvp_lose' => (clone $matchPlayers)->where(function ($q) {
                $q->where('medal', 'mvp_lose')
                    ->orWhere(function ($qq) {
                        $qq->where('medal', 'mvp')->where('result', 'lose');
                    });
            })->count(),
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
            ->when($patchId, fn ($q) => $q->whereHas('gameMatch', fn ($qq) => $qq->where('patch_id', $patchId)))
            ->with(['gameMatch:id,match_date', 'hero:id,name,icon_url', 'role:id,name'])
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn ($mp) => [
                'match_id' => $mp->match_id,
                'match_date' => $mp->gameMatch?->match_date?->format('Y-m-d'),
                'hero' => $mp->hero ? [
                    'id' => $mp->hero->id,
                    'name' => $mp->hero->name,
                    'icon_url' => $mp->hero->icon_url,
                ] : null,
                'role' => $mp->role?->name,
                'kills' => $mp->kills,
                'deaths' => $mp->deaths,
                'assists' => $mp->assists,
                'rating' => $mp->rating,
                'medal' => $mp->medal,
                'result' => $mp->result,
            ]);

        $ratingTrend = MatchPlayer::where('player_id', $playerId)
            ->when($patchId, fn ($q) => $q->whereHas('gameMatch', fn ($qq) => $qq->where('patch_id', $patchId)))
            ->whereNotNull('rating')
            ->orderBy('id')
            ->select('match_id', 'rating')
            ->limit(50)
            ->get();

        // Win/lose streak
        $latestResults = MatchPlayer::where('player_id', $playerId)
            ->when($patchId, fn ($q) => $q->whereHas('gameMatch', fn ($qq) => $qq->where('patch_id', $patchId)))
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
            ->when($patchId, fn ($q) => $q->where('game_matches.patch_id', $patchId))
            ->orderBy('game_matches.match_date')
            ->select('game_matches.match_date')
            ->first();

        $latestMatch = MatchPlayer::where('player_id', $playerId)
            ->join('game_matches', 'match_players.match_id', '=', 'game_matches.id')
            ->when($patchId, fn ($q) => $q->where('game_matches.patch_id', $patchId))
            ->orderByDesc('game_matches.match_date')
            ->select('game_matches.match_date')
            ->first();

        $recentFormResults = MatchPlayer::where('player_id', $playerId)
            ->when($patchId, fn ($q) => $q->whereHas('gameMatch', fn ($qq) => $qq->where('patch_id', $patchId)))
            ->orderByDesc('id')
            ->limit(5)
            ->pluck('result')
            ->toArray();
        $recentWins = collect($recentFormResults)->filter(fn ($r) => $r === 'win')->count();
        $recentRate = count($recentFormResults) > 0 ? ($recentWins / count($recentFormResults)) * 100 : 0;
        $formMeter = $recentRate >= 70 ? 'hot' : ($recentRate >= 40 ? 'warm' : 'cold');

        $recommendedRole = $rolesPlayed->sort(function ($a, $b) {
            $aMatches = (int) $a['times_played'];
            $bMatches = (int) $b['times_played'];
            $aScore = $aMatches >= 2 ? (float) $a['win_rate'] : ((float) $a['win_rate'] * 0.6);
            $bScore = $bMatches >= 2 ? (float) $b['win_rate'] : ((float) $b['win_rate'] * 0.6);

            if ($bScore !== $aScore) {
                return $bScore <=> $aScore;
            }

            return $bMatches <=> $aMatches;
        })->first();

        $kdaRatio = $totalDeaths > 0 ? ($totalKills + $totalAssists) / $totalDeaths : ($totalKills + $totalAssists);
        $uniqueRoles = $rolesPlayed->count();
        $avgRatingValue = $avgRating ? (float) $avgRating : 0;
        $winStreak = $streakType === 'win' ? $streak : 0;

        $achievementCatalog = [
            [
                'key' => 'mvp_legend',
                'label' => 'MVP Legend',
                'description' => 'Raih 10+ MVP sepanjang karier.',
                'tier' => 'legendary',
                'icon' => 'crown',
                'unlocked' => $medals['mvp'] >= 10,
            ],
            [
                'key' => 'mvp_hunter',
                'label' => 'MVP Hunter',
                'description' => 'Raih 3+ MVP.',
                'tier' => 'epic',
                'icon' => 'crown',
                'unlocked' => $medals['mvp'] >= 3 && $medals['mvp'] < 10,
            ],
            [
                'key' => 'clutch_god',
                'label' => 'Clutch God',
                'description' => 'Rata-rata rating ≥ 8.0.',
                'tier' => 'legendary',
                'icon' => 'star',
                'unlocked' => $totalMatches >= 3 && $avgRatingValue >= 8,
            ],
            [
                'key' => 'win_machine',
                'label' => 'Win Machine',
                'description' => 'Win rate ≥ 70% (min. 3 match).',
                'tier' => 'epic',
                'icon' => 'trophy',
                'unlocked' => $totalMatches >= 3 && $totalWins >= $totalMatches * 0.7,
            ],
            [
                'key' => 'unstoppable',
                'label' => 'Unstoppable',
                'description' => '3+ kemenangan beruntun.',
                'tier' => 'epic',
                'icon' => 'bolt',
                'unlocked' => $winStreak >= 3,
            ],
            [
                'key' => 'dominator',
                'label' => 'Dominator',
                'description' => 'Raih 5+ medali Gold.',
                'tier' => 'epic',
                'icon' => 'gem',
                'unlocked' => ($medals['gold'] ?? 0) >= 5,
            ],
            [
                'key' => 'iron_wall',
                'label' => 'Iron Wall',
                'description' => 'KDA ratio ≥ 3.0.',
                'tier' => 'rare',
                'icon' => 'shield',
                'unlocked' => $totalMatches >= 3 && $kdaRatio >= 3,
            ],
            [
                'key' => 'assist_king',
                'label' => 'Assist King',
                'description' => '50+ total assist.',
                'tier' => 'rare',
                'icon' => 'handshake',
                'unlocked' => $totalAssists >= 50,
            ],
            [
                'key' => 'sharpshooter',
                'label' => 'Sharpshooter',
                'description' => '50+ total kill.',
                'tier' => 'rare',
                'icon' => 'target',
                'unlocked' => $totalKills >= 50,
            ],
            [
                'key' => 'hot_streak',
                'label' => 'Hot Streak',
                'description' => '5 match terakhir dalam performa panas.',
                'tier' => 'rare',
                'icon' => 'flame',
                'unlocked' => $formMeter === 'hot' && count($recentFormResults) >= 3,
            ],
            [
                'key' => 'versatile',
                'label' => 'Versatile',
                'description' => 'Main 3+ role berbeda.',
                'tier' => 'rare',
                'icon' => 'layers',
                'unlocked' => $uniqueRoles >= 3,
            ],
            [
                'key' => 'hero_master',
                'label' => 'Hero Master',
                'description' => 'Gunakan 5+ hero berbeda.',
                'tier' => 'rare',
                'icon' => 'users',
                'unlocked' => $heroPoolDiversity >= 5,
            ],
            [
                'key' => 'veteran',
                'label' => 'Veteran',
                'description' => '20+ match dimainkan.',
                'tier' => 'common',
                'icon' => 'medal',
                'unlocked' => $totalMatches >= 20,
            ],
            [
                'key' => 'rookie',
                'label' => 'Rookie',
                'description' => 'Match pertama tercatat.',
                'tier' => 'common',
                'icon' => 'sparkles',
                'unlocked' => $totalMatches >= 1 && $totalMatches < 5,
            ],
        ];

        $achievements = array_values(array_map(
            fn ($a) => [
                'key' => $a['key'],
                'label' => $a['label'],
                'description' => $a['description'],
                'tier' => $a['tier'],
                'icon' => $a['icon'],
            ],
            array_filter($achievementCatalog, fn ($a) => $a['unlocked'])
        ));

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
            'recent_form' => array_map(fn ($r) => $r === 'win' ? 'W' : 'L', $recentFormResults),
            'form_meter' => $formMeter,
            'recommended_role' => $recommendedRole ? [
                'role' => $recommendedRole['role'],
                'times_played' => $recommendedRole['times_played'],
                'win_rate' => $recommendedRole['win_rate'],
            ] : null,
            'achievements' => $achievements,
            'mvp_rate' => $mvpRate,
            'hero_pool_diversity' => $heroPoolDiversity,
            'first_match_date' => $firstMatch?->match_date,
            'latest_match_date' => $latestMatch?->match_date,
        ];
    }

    public function heroStats(?Request $request = null): array
    {
        $patchId = $request?->get('patch_id');

        return Hero::select('heroes.*')
            ->selectRaw('COUNT(match_players.id) as usage_count')
            ->selectRaw('SUM(CASE WHEN match_players.result = \'win\' THEN 1 ELSE 0 END) as wins')
            ->selectRaw('AVG(match_players.rating) as avg_rating')
            ->selectRaw('SUM(CASE WHEN match_players.medal IN (\'mvp\', \'mvp_win\', \'mvp_lose\') THEN 1 ELSE 0 END) as mvp_count')
            ->leftJoin('match_players', 'heroes.id', '=', 'match_players.hero_id')
            ->leftJoin('game_matches', 'match_players.match_id', '=', 'game_matches.id')
            ->when($patchId, fn ($q) => $q->where('game_matches.patch_id', $patchId))
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

    public function roleStats(?Request $request = null): array
    {
        $patchId = $request?->get('patch_id');

        return Role::select('roles.*')
            ->selectRaw('COUNT(match_players.id) as usage_count')
            ->selectRaw('SUM(CASE WHEN match_players.result = \'win\' THEN 1 ELSE 0 END) as wins')
            ->selectRaw('AVG(match_players.rating) as avg_rating')
            ->leftJoin('match_players', 'roles.id', '=', 'match_players.role_id')
            ->leftJoin('game_matches', 'match_players.match_id', '=', 'game_matches.id')
            ->when($patchId, fn ($q) => $q->where('game_matches.patch_id', $patchId))
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

    public function synergy(?Request $request = null): array
    {
        $patchId = $request?->get('patch_id');
        $matches = GameMatch::with(['matchPlayers.player'])
            ->when($patchId, fn ($q) => $q->where('patch_id', $patchId))
            ->get(['id', 'winner']);

        $duoStats = [];
        $trioStats = [];

        foreach ($matches as $match) {
            foreach (['team_a', 'team_b'] as $team) {
                $players = $match->matchPlayers->where('team', $team)->values();
                if ($players->count() < 2) {
                    continue;
                }

                for ($i = 0; $i < $players->count(); $i++) {
                    for ($j = $i + 1; $j < $players->count(); $j++) {
                        $ids = [$players[$i]->player_id, $players[$j]->player_id];
                        sort($ids);
                        $key = implode('-', $ids);
                        $duoStats[$key] ??= ['player_ids' => $ids, 'matches' => 0, 'wins' => 0, 'rating_sum' => 0];
                        $duoStats[$key]['matches']++;
                        $duoStats[$key]['wins'] += $match->winner === $team ? 1 : 0;
                        $duoStats[$key]['rating_sum'] += ((float) $players[$i]->rating + (float) $players[$j]->rating) / 2;
                    }
                }

                if ($players->count() < 3) {
                    continue;
                }

                for ($i = 0; $i < $players->count(); $i++) {
                    for ($j = $i + 1; $j < $players->count(); $j++) {
                        for ($k = $j + 1; $k < $players->count(); $k++) {
                            $ids = [$players[$i]->player_id, $players[$j]->player_id, $players[$k]->player_id];
                            sort($ids);
                            $key = implode('-', $ids);
                            $trioStats[$key] ??= ['player_ids' => $ids, 'matches' => 0, 'wins' => 0, 'rating_sum' => 0];
                            $trioStats[$key]['matches']++;
                            $trioStats[$key]['wins'] += $match->winner === $team ? 1 : 0;
                            $trioStats[$key]['rating_sum'] += ((float) $players[$i]->rating + (float) $players[$j]->rating + (float) $players[$k]->rating) / 3;
                        }
                    }
                }
            }
        }

        $playerMap = Player::pluck('username', 'id');
        $mapRows = function (array $rows) use ($playerMap) {
            return collect($rows)
                ->filter(fn ($row) => $row['matches'] >= 2)
                ->map(function ($row) use ($playerMap) {
                    $matches = (int) $row['matches'];
                    $wins = (int) $row['wins'];
                    return [
                        'players' => collect($row['player_ids'])->map(fn ($id) => [
                            'id' => $id,
                            'username' => $playerMap[$id] ?? 'Unknown',
                        ])->values()->all(),
                        'matches' => $matches,
                        'wins' => $wins,
                        'win_rate' => $matches > 0 ? round(($wins / $matches) * 100, 1) : 0,
                        'avg_rating' => $matches > 0 ? round(((float) $row['rating_sum']) / $matches, 2) : 0,
                    ];
                })
                ->sortByDesc(fn ($row) => ($row['win_rate'] * 0.7) + ($row['avg_rating'] * 3))
                ->values()
                ->take(10)
                ->all();
        };

        return [
            'top_duos' => $mapRows($duoStats),
            'top_trios' => $mapRows($trioStats),
        ];
    }

    public function trends(?Request $request = null): array
    {
        $patchId = $request?->get('patch_id');

        $winRateTrend = GameMatch::selectRaw('match_date')
            ->selectRaw('COUNT(*) as matches_count')
            ->when($patchId, fn ($q) => $q->where('patch_id', $patchId))
            ->groupBy('match_date')
            ->orderBy('match_date')
            ->limit(30)
            ->get()
            ->map(function ($day) use ($patchId) {
                $dayPlayers = MatchPlayer::whereHas('gameMatch', fn ($q) => $q->where('match_date', $day->match_date)
                    ->when($patchId, fn ($qq) => $qq->where('patch_id', $patchId)));
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
            ->when($patchId, fn ($q) => $q->where('game_matches.patch_id', $patchId))
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
