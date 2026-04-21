<?php

namespace App\Services;

use App\Models\GameMatch;
use App\Models\MatchPlayer;
use App\Models\Player;

class HeadToHeadService
{
    public function comparePlayers(int $playerAId, int $playerBId): array
    {
        $playerA = Player::findOrFail($playerAId);
        $playerB = Player::findOrFail($playerBId);

        $matchIdsA = MatchPlayer::where('player_id', $playerAId)->pluck('match_id');
        $matchIdsB = MatchPlayer::where('player_id', $playerBId)->pluck('match_id');
        $sharedIds = $matchIdsA->intersect($matchIdsB)->values();

        $matches = GameMatch::with([
                'matchPlayers.player:id,username,avatar_url',
                'matchPlayers.hero:id,name,icon_url',
                'matchPlayers.role:id,name',
            ])
            ->whereIn('id', $sharedIds)
            ->orderByDesc('match_date')
            ->get();

        $asOpponents = [];
        $asTeammates = [];
        $aWins = 0;
        $bWins = 0;
        $draws = 0;

        foreach ($matches as $match) {
            $a = $match->matchPlayers->firstWhere('player_id', $playerAId);
            $b = $match->matchPlayers->firstWhere('player_id', $playerBId);
            if (! $a || ! $b) {
                continue;
            }

            $sameTeam = $a->team === $b->team;
            $entry = [
                'match_id' => $match->id,
                'match_date' => $match->match_date?->format('Y-m-d'),
                'a' => [
                    'team' => $a->team,
                    'hero_id' => $a->hero_id,
                    'hero' => $a->hero?->name,
                    'hero_icon' => $a->hero?->icon_url,
                    'role' => $a->role?->name,
                    'kills' => (int) $a->kills,
                    'deaths' => (int) $a->deaths,
                    'assists' => (int) $a->assists,
                    'kda' => "{$a->kills}/{$a->deaths}/{$a->assists}",
                    'rating' => $a->rating !== null ? (float) $a->rating : null,
                    'result' => $a->result,
                ],
                'b' => [
                    'team' => $b->team,
                    'hero_id' => $b->hero_id,
                    'hero' => $b->hero?->name,
                    'hero_icon' => $b->hero?->icon_url,
                    'role' => $b->role?->name,
                    'kills' => (int) $b->kills,
                    'deaths' => (int) $b->deaths,
                    'assists' => (int) $b->assists,
                    'kda' => "{$b->kills}/{$b->deaths}/{$b->assists}",
                    'rating' => $b->rating !== null ? (float) $b->rating : null,
                    'result' => $b->result,
                ],
            ];

            if ($sameTeam) {
                $asTeammates[] = $entry;
                if ($a->result === 'win') {
                    $aWins++;
                    $bWins++;
                }
            } else {
                $asOpponents[] = $entry;
                if ($a->result === 'win') {
                    $aWins++;
                } elseif ($b->result === 'win') {
                    $bWins++;
                } else {
                    $draws++;
                }
            }
        }

        $h2hWinsA = collect($asOpponents)->where('a.result', 'win')->count();
        $h2hWinsB = collect($asOpponents)->where('b.result', 'win')->count();

        $summary = [
            'total_matches' => count($matches),
            'as_opponents' => count($asOpponents),
            'as_teammates' => count($asTeammates),
            'player_a_wins_vs_b' => $h2hWinsA,
            'player_b_wins_vs_a' => $h2hWinsB,
        ];

        $rivalry = $this->buildH2hRivalry($asOpponents, $playerA->username, $playerB->username);

        return [
            'player_a' => $this->playerFormCard($playerA),
            'player_b' => $this->playerFormCard($playerB),
            'summary' => $summary,
            'rivalry' => $rivalry,
            'head_to_head' => $asOpponents,
            'as_teammates' => $asTeammates,
            'favorite_heroes' => [
                'a' => $this->favoriteHeroes($playerAId),
                'b' => $this->favoriteHeroes($playerBId),
            ],
            'favorite_heroes_h2h' => [
                'a' => $this->favoriteHeroesInEntries($asOpponents, 'a'),
                'b' => $this->favoriteHeroesInEntries($asOpponents, 'b'),
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $asOpponents
     * @return array<string, mixed>
     */
    private function buildH2hRivalry(array $asOpponents, string $nameA, string $nameB): array
    {
        $n = count($asOpponents);
        if ($n === 0) {
            return [
                'leader' => 'tie',
                'leader_label' => 'Belum ada duel',
                'games' => 0,
                'player_a' => null,
                'player_b' => null,
                'streak' => null,
                'dominance_pct_a' => 50.0,
            ];
        }

        $sa = [
            'sum_rating' => 0.0,
            'rating_n' => 0,
            'kills' => 0,
            'deaths' => 0,
            'assists' => 0,
        ];
        $sb = [
            'sum_rating' => 0.0,
            'rating_n' => 0,
            'kills' => 0,
            'deaths' => 0,
            'assists' => 0,
        ];

        foreach ($asOpponents as $e) {
            $pa = $e['a'];
            $pb = $e['b'];
            $sa['kills'] += $pa['kills'];
            $sa['deaths'] += $pa['deaths'];
            $sa['assists'] += $pa['assists'];
            if ($pa['rating'] !== null) {
                $sa['sum_rating'] += $pa['rating'];
                $sa['rating_n']++;
            }
            $sb['kills'] += $pb['kills'];
            $sb['deaths'] += $pb['deaths'];
            $sb['assists'] += $pb['assists'];
            if ($pb['rating'] !== null) {
                $sb['sum_rating'] += $pb['rating'];
                $sb['rating_n']++;
            }
        }

        $games = $n;
        $avgRating = fn (array $s) => $s['rating_n'] > 0 ? round($s['sum_rating'] / $s['rating_n'], 2) : null;
        $avgK = fn (array $s) => $games > 0 ? round($s['kills'] / $games, 2) : 0.0;
        $avgD = fn (array $s) => $games > 0 ? round($s['deaths'] / $games, 2) : 0.0;
        $avgAs = fn (array $s) => $games > 0 ? round($s['assists'] / $games, 2) : 0.0;
        $kdaRatio = fn (array $s) => $s['deaths'] > 0
            ? round(($s['kills'] + $s['assists']) / max(1, $s['deaths']), 2)
            : round($s['kills'] + $s['assists'], 2);

        $winsA = collect($asOpponents)->where('a.result', 'win')->count();
        $winsB = collect($asOpponents)->where('b.result', 'win')->count();

        $rowA = [
            'wins' => $winsA,
            'win_rate' => round(($winsA / $games) * 100, 1),
            'avg_rating' => $avgRating($sa),
            'avg_kills' => $avgK($sa),
            'avg_deaths' => $avgD($sa),
            'avg_assists' => $avgAs($sa),
            'kda_ratio' => $kdaRatio($sa),
        ];
        $rowB = [
            'wins' => $winsB,
            'win_rate' => round(($winsB / $games) * 100, 1),
            'avg_rating' => $avgRating($sb),
            'avg_kills' => $avgK($sb),
            'avg_deaths' => $avgD($sb),
            'avg_assists' => $avgAs($sb),
            'kda_ratio' => $kdaRatio($sb),
        ];

        $leader = 'tie';
        $reason = 'tie_even';
        $label = 'Rivalitas seimbang';

        if ($winsA > $winsB) {
            $leader = 'a';
            $reason = 'more_wins';
            $label = "{$nameA} unggul di head-to-head ({$winsA}–{$winsB})";
        } elseif ($winsB > $winsA) {
            $leader = 'b';
            $reason = 'more_wins';
            $label = "{$nameB} unggul di head-to-head ({$winsB}–{$winsA})";
        } else {
            $ra = $rowA['avg_rating'];
            $rb = $rowB['avg_rating'];
            if ($ra !== null && $rb !== null && $ra !== $rb) {
                if ($ra > $rb) {
                    $leader = 'a';
                    $reason = 'better_avg_rating';
                    $label = "{$nameA} unggul (seri menang, rating H2H lebih tinggi)";
                } elseif ($rb > $ra) {
                    $leader = 'b';
                    $reason = 'better_avg_rating';
                    $label = "{$nameB} unggul (seri menang, rating H2H lebih tinggi)";
                }
            }
            if ($leader === 'tie' && ($rowA['kda_ratio'] ?? 0) !== ($rowB['kda_ratio'] ?? 0)) {
                if ($rowA['kda_ratio'] > $rowB['kda_ratio']) {
                    $leader = 'a';
                    $reason = 'better_kda';
                    $label = "{$nameA} unggul (seri menang, K/D+A lebih baik)";
                } elseif ($rowB['kda_ratio'] > $rowA['kda_ratio']) {
                    $leader = 'b';
                    $reason = 'better_kda';
                    $label = "{$nameB} unggul (seri menang, K/D+A lebih baik)";
                }
            }
        }

        $streak = $this->h2hWinStreak($asOpponents, $nameA, $nameB);

        $totalWins = $winsA + $winsB;
        $dominancePctA = $totalWins > 0 ? round(($winsA / $totalWins) * 100, 1) : 50.0;

        return [
            'leader' => $leader,
            'reason' => $reason,
            'leader_label' => $label,
            'games' => $games,
            'player_a' => $rowA,
            'player_b' => $rowB,
            'streak' => $streak,
            'dominance_pct_a' => $dominancePctA,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $asOpponents  Newest first (same order as API).
     * @return array{side: 'a'|'b', count: int, label: string}|null
     */
    private function h2hWinStreak(array $asOpponents, string $nameA, string $nameB): ?array
    {
        if ($asOpponents === []) {
            return null;
        }

        $first = $asOpponents[0];
        $winnerSide = ($first['a']['result'] ?? '') === 'win' ? 'a' : 'b';
        $count = 0;
        foreach ($asOpponents as $e) {
            $w = ($e['a']['result'] ?? '') === 'win' ? 'a' : 'b';
            if ($w === $winnerSide) {
                $count++;
            } else {
                break;
            }
        }

        return [
            'side' => $winnerSide,
            'count' => $count,
            'label' => $winnerSide === 'a' ? $nameA : $nameB,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<int, array{hero: ?string, hero_icon: ?string, matches: int, wins: int, win_rate: float}>
     */
    private function favoriteHeroesInEntries(array $entries, string $side): array
    {
        $byHero = [];
        foreach ($entries as $e) {
            $p = $e[$side] ?? null;
            if (! is_array($p) || empty($p['hero_id'])) {
                continue;
            }
            $hid = (int) $p['hero_id'];
            if (! isset($byHero[$hid])) {
                $byHero[$hid] = [
                    'hero' => $p['hero'] ?? null,
                    'hero_icon' => $p['hero_icon'] ?? null,
                    'matches' => 0,
                    'wins' => 0,
                ];
            }
            $byHero[$hid]['matches']++;
            if (($p['result'] ?? '') === 'win') {
                $byHero[$hid]['wins']++;
            }
        }

        return collect($byHero)
            ->sortByDesc('matches')
            ->take(5)
            ->values()
            ->map(fn ($row) => [
                'hero' => $row['hero'],
                'hero_icon' => $row['hero_icon'],
                'matches' => $row['matches'],
                'wins' => $row['wins'],
                'win_rate' => $row['matches'] > 0 ? round(($row['wins'] / $row['matches']) * 100, 1) : 0,
            ])
            ->toArray();
    }

    public function compareTeams(array $playerIdsA, array $playerIdsB): array
    {
        $playerIdsA = array_values(array_filter(array_map('intval', $playerIdsA)));
        $playerIdsB = array_values(array_filter(array_map('intval', $playerIdsB)));

        if (empty($playerIdsA) || empty($playerIdsB)) {
            return [
                'summary' => ['total_matches' => 0, 'team_a_wins' => 0, 'team_b_wins' => 0],
                'matches' => [],
            ];
        }

        $matches = GameMatch::with([
                'matchPlayers.player:id,username,avatar_url',
                'matchPlayers.hero:id,name,icon_url',
            ])
            ->orderByDesc('match_date')
            ->get();

        $teamAWins = 0;
        $teamBWins = 0;
        $entries = [];

        foreach ($matches as $match) {
            $mps = $match->matchPlayers;
            $sideA = $this->sideWithAll($mps, $playerIdsA);
            $sideB = $sideA === 'team_a' ? 'team_b' : ($sideA === 'team_b' ? 'team_a' : null);
            if (! $sideA) {
                continue;
            }
            $allOnB = $mps->where('team', $sideB)->pluck('player_id')->intersect($playerIdsB)->count();
            if ($allOnB !== count($playerIdsB)) {
                continue;
            }
            if ($match->winner === $sideA) {
                $teamAWins++;
            } else {
                $teamBWins++;
            }
            $entries[] = [
                'match_id' => $match->id,
                'match_date' => $match->match_date?->format('Y-m-d'),
                'winner' => $match->winner === $sideA ? 'team_a' : 'team_b',
                'roster_a' => $mps->where('team', $sideA)->map(fn ($mp) => [
                    'player' => $mp->player?->username,
                    'hero' => $mp->hero?->name,
                    'hero_icon' => $mp->hero?->icon_url,
                ])->values(),
                'roster_b' => $mps->where('team', $sideB)->map(fn ($mp) => [
                    'player' => $mp->player?->username,
                    'hero' => $mp->hero?->name,
                    'hero_icon' => $mp->hero?->icon_url,
                ])->values(),
            ];
        }

        return [
            'summary' => [
                'total_matches' => count($entries),
                'team_a_wins' => $teamAWins,
                'team_b_wins' => $teamBWins,
                'team_a_win_rate' => count($entries) > 0 ? round(($teamAWins / count($entries)) * 100, 1) : 0,
            ],
            'matches' => $entries,
        ];
    }

    private function sideWithAll($matchPlayers, array $playerIds): ?string
    {
        foreach (['team_a', 'team_b'] as $team) {
            $teamPlayerIds = $matchPlayers->where('team', $team)->pluck('player_id')->all();
            if (count(array_intersect($playerIds, $teamPlayerIds)) === count($playerIds)) {
                return $team;
            }
        }

        return null;
    }

    private function playerFormCard(Player $player): array
    {
        $recent = MatchPlayer::where('player_id', $player->id)
            ->orderByDesc('id')
            ->limit(5)
            ->get(['result', 'kills', 'deaths', 'assists', 'rating']);

        $wins = $recent->where('result', 'win')->count();
        $total = MatchPlayer::where('player_id', $player->id)->count();
        $totalWins = MatchPlayer::where('player_id', $player->id)->where('result', 'win')->count();

        return [
            'id' => $player->id,
            'username' => $player->username,
            'avatar_url' => $player->avatar_url,
            'total_matches' => $total,
            'overall_win_rate' => $total > 0 ? round(($totalWins / $total) * 100, 1) : 0,
            'recent_form' => $recent->pluck('result')->map(fn ($r) => $r === 'win' ? 'W' : 'L')->all(),
            'recent_win_rate' => $recent->count() > 0 ? round(($wins / $recent->count()) * 100, 1) : 0,
        ];
    }

    private function favoriteHeroes(int $playerId, int $limit = 5): array
    {
        return MatchPlayer::where('player_id', $playerId)
            ->select('hero_id')
            ->selectRaw('COUNT(*) as matches')
            ->selectRaw("SUM(CASE WHEN result = 'win' THEN 1 ELSE 0 END) as wins")
            ->groupBy('hero_id')
            ->with('hero:id,name,icon_url')
            ->orderByDesc('matches')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'hero' => $row->hero?->name,
                'hero_icon' => $row->hero?->icon_url,
                'matches' => (int) $row->matches,
                'wins' => (int) $row->wins,
                'win_rate' => $row->matches > 0 ? round(($row->wins / $row->matches) * 100, 1) : 0,
            ])
            ->toArray();
    }
}
