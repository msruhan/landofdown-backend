<?php

namespace App\Services;

use App\Models\DraftPick;
use App\Models\GameMatch;
use App\Models\Hero;
use App\Models\MatchPlayer;
use Illuminate\Support\Facades\DB;

class DraftAnalysisService
{
    public function overview(?int $patchId = null): array
    {
        $query = MatchPlayer::query()
            ->join('game_matches', 'match_players.match_id', '=', 'game_matches.id');

        if ($patchId) {
            $query->where('game_matches.patch_id', $patchId);
        }

        $heroStats = (clone $query)
            ->join('heroes', 'match_players.hero_id', '=', 'heroes.id')
            ->select('heroes.id', 'heroes.name', 'heroes.icon_url')
            ->selectRaw('COUNT(*) as picks')
            ->selectRaw("SUM(CASE WHEN match_players.result = 'win' THEN 1 ELSE 0 END) as wins")
            ->groupBy('heroes.id', 'heroes.name', 'heroes.icon_url')
            ->orderByDesc('picks')
            ->limit(15)
            ->get()
            ->map(fn ($h) => [
                'hero_id' => $h->id,
                'hero_name' => $h->name,
                'icon_url' => $h->icon_url,
                'picks' => (int) $h->picks,
                'wins' => (int) $h->wins,
                'win_rate' => $h->picks > 0 ? round(($h->wins / $h->picks) * 100, 1) : 0,
            ])
            ->toArray();

        $banStats = DraftPick::query()
            ->join('heroes', 'draft_picks.hero_id', '=', 'heroes.id')
            ->when($patchId, fn ($q) => $q->join('game_matches', 'draft_picks.match_id', '=', 'game_matches.id')
                ->where('game_matches.patch_id', $patchId))
            ->where('draft_picks.action', 'ban')
            ->select('heroes.id', 'heroes.name', 'heroes.icon_url')
            ->selectRaw('COUNT(*) as bans')
            ->groupBy('heroes.id', 'heroes.name', 'heroes.icon_url')
            ->orderByDesc('bans')
            ->limit(10)
            ->get()
            ->map(fn ($h) => [
                'hero_id' => $h->id,
                'hero_name' => $h->name,
                'icon_url' => $h->icon_url,
                'bans' => (int) $h->bans,
            ])
            ->toArray();

        return [
            'top_picks' => $heroStats,
            'top_bans' => $banStats,
            'hero_pair_synergy' => $this->heroPairSynergy($patchId, 10),
            'hero_counters' => $this->topCounters($patchId, 10),
        ];
    }

    public function heroPairSynergy(?int $patchId = null, int $limit = 10): array
    {
        $matches = MatchPlayer::query()
            ->join('game_matches', 'match_players.match_id', '=', 'game_matches.id')
            ->when($patchId, fn ($q) => $q->where('game_matches.patch_id', $patchId))
            ->select('match_id', 'team', 'hero_id', 'result')
            ->get()
            ->groupBy(fn ($mp) => $mp->match_id.'|'.$mp->team);

        $pairs = [];
        foreach ($matches as $players) {
            $items = $players->values();
            $count = $items->count();
            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $a = (int) $items[$i]->hero_id;
                    $b = (int) $items[$j]->hero_id;
                    if ($a === $b) {
                        continue;
                    }
                    [$low, $high] = $a < $b ? [$a, $b] : [$b, $a];
                    $key = $low.'-'.$high;
                    if (! isset($pairs[$key])) {
                        $pairs[$key] = ['hero_a' => $low, 'hero_b' => $high, 'matches' => 0, 'wins' => 0];
                    }
                    $pairs[$key]['matches']++;
                    if ($items[$i]->result === 'win') {
                        $pairs[$key]['wins']++;
                    }
                }
            }
        }

        $heroes = Hero::select('id', 'name', 'icon_url')->get()->keyBy('id');

        $sorted = collect($pairs)
            ->filter(fn ($p) => $p['matches'] >= 2)
            ->map(function ($p) use ($heroes) {
                $wr = $p['matches'] > 0 ? round(($p['wins'] / $p['matches']) * 100, 1) : 0;

                return [
                    'hero_a' => [
                        'id' => $p['hero_a'],
                        'name' => $heroes[$p['hero_a']]?->name ?? 'Unknown',
                        'icon_url' => $heroes[$p['hero_a']]?->icon_url,
                    ],
                    'hero_b' => [
                        'id' => $p['hero_b'],
                        'name' => $heroes[$p['hero_b']]?->name ?? 'Unknown',
                        'icon_url' => $heroes[$p['hero_b']]?->icon_url,
                    ],
                    'matches' => $p['matches'],
                    'wins' => $p['wins'],
                    'win_rate' => $wr,
                ];
            })
            ->sortByDesc(fn ($p) => [$p['win_rate'], $p['matches']])
            ->take($limit)
            ->values()
            ->toArray();

        return $sorted;
    }

    public function topCounters(?int $patchId = null, int $limit = 10): array
    {
        $matches = MatchPlayer::query()
            ->join('game_matches', 'match_players.match_id', '=', 'game_matches.id')
            ->when($patchId, fn ($q) => $q->where('game_matches.patch_id', $patchId))
            ->select('match_id', 'team', 'hero_id', 'result')
            ->get()
            ->groupBy('match_id');

        $counters = [];
        foreach ($matches as $items) {
            $teamA = $items->where('team', 'team_a');
            $teamB = $items->where('team', 'team_b');
            foreach ($teamA as $a) {
                foreach ($teamB as $b) {
                    $key = $a->hero_id.'|'.$b->hero_id;
                    if (! isset($counters[$key])) {
                        $counters[$key] = ['hero' => (int) $a->hero_id, 'enemy' => (int) $b->hero_id, 'matches' => 0, 'wins' => 0];
                    }
                    $counters[$key]['matches']++;
                    if ($a->result === 'win') {
                        $counters[$key]['wins']++;
                    }
                }
            }
        }

        $heroes = Hero::select('id', 'name', 'icon_url')->get()->keyBy('id');

        return collect($counters)
            ->filter(fn ($c) => $c['matches'] >= 2)
            ->map(function ($c) use ($heroes) {
                $wr = $c['matches'] > 0 ? round(($c['wins'] / $c['matches']) * 100, 1) : 0;

                return [
                    'hero' => [
                        'id' => $c['hero'],
                        'name' => $heroes[$c['hero']]?->name ?? 'Unknown',
                        'icon_url' => $heroes[$c['hero']]?->icon_url,
                    ],
                    'enemy' => [
                        'id' => $c['enemy'],
                        'name' => $heroes[$c['enemy']]?->name ?? 'Unknown',
                        'icon_url' => $heroes[$c['enemy']]?->icon_url,
                    ],
                    'matches' => $c['matches'],
                    'wins' => $c['wins'],
                    'win_rate' => $wr,
                ];
            })
            ->sortByDesc(fn ($c) => [$c['win_rate'], $c['matches']])
            ->take($limit)
            ->values()
            ->toArray();
    }

    public function recommend(array $allyIds, array $enemyIds, ?int $limit = 10): array
    {
        $allyIds = array_values(array_filter(array_map('intval', $allyIds)));
        $enemyIds = array_values(array_filter(array_map('intval', $enemyIds)));
        $excluded = array_merge($allyIds, $enemyIds);

        $candidates = Hero::select('id', 'name', 'icon_url', 'role_id')
            ->whereNotIn('id', $excluded ?: [0])
            ->get();

        $matches = MatchPlayer::query()
            ->select('match_id', 'team', 'hero_id', 'result')
            ->get()
            ->groupBy('match_id');

        $pairStats = [];
        $counterStats = [];
        foreach ($matches as $items) {
            foreach (['team_a', 'team_b'] as $team) {
                $teamItems = $items->where('team', $team)->values();
                for ($i = 0; $i < $teamItems->count(); $i++) {
                    for ($j = $i + 1; $j < $teamItems->count(); $j++) {
                        $a = (int) $teamItems[$i]->hero_id;
                        $b = (int) $teamItems[$j]->hero_id;
                        $win = $teamItems[$i]->result === 'win';
                        $this->accumulatePair($pairStats, $a, $b, $win);
                        $this->accumulatePair($pairStats, $b, $a, $win);
                    }
                }
            }
            $teamA = $items->where('team', 'team_a');
            $teamB = $items->where('team', 'team_b');
            foreach ($teamA as $a) {
                foreach ($teamB as $b) {
                    $this->accumulatePair($counterStats, (int) $a->hero_id, (int) $b->hero_id, $a->result === 'win');
                    $this->accumulatePair($counterStats, (int) $b->hero_id, (int) $a->hero_id, $b->result === 'win');
                }
            }
        }

        $recommendations = $candidates->map(function ($hero) use ($allyIds, $enemyIds, $pairStats, $counterStats) {
            $synergyTotal = 0.0;
            $synergyCount = 0;
            foreach ($allyIds as $allyId) {
                $key = $hero->id.'|'.$allyId;
                if (isset($pairStats[$key]) && $pairStats[$key]['n'] > 0) {
                    $synergyTotal += $pairStats[$key]['w'] / $pairStats[$key]['n'];
                    $synergyCount++;
                }
            }
            $synergy = $synergyCount > 0 ? $synergyTotal / $synergyCount : 0.5;

            $counterTotal = 0.0;
            $counterCount = 0;
            foreach ($enemyIds as $enemyId) {
                $key = $hero->id.'|'.$enemyId;
                if (isset($counterStats[$key]) && $counterStats[$key]['n'] > 0) {
                    $counterTotal += $counterStats[$key]['w'] / $counterStats[$key]['n'];
                    $counterCount++;
                }
            }
            $counter = $counterCount > 0 ? $counterTotal / $counterCount : 0.5;

            $score = ($synergy * 0.55) + ($counter * 0.45);

            return [
                'hero' => [
                    'id' => $hero->id,
                    'name' => $hero->name,
                    'icon_url' => $hero->icon_url,
                    'role_id' => $hero->role_id,
                ],
                'synergy_rate' => round($synergy * 100, 1),
                'counter_rate' => round($counter * 100, 1),
                'score' => round($score * 100, 1),
                'sample' => $synergyCount + $counterCount,
            ];
        })
            ->sortByDesc('score')
            ->take($limit)
            ->values()
            ->toArray();

        return $recommendations;
    }

    public function matchDraft(int $matchId): array
    {
        $match = GameMatch::with([
            'patch',
            'draftPicks.hero',
            'matchPlayers.hero',
            'matchPlayers.player',
            'matchPlayers.role',
        ])->findOrFail($matchId);

        $picks = $match->draftPicks
            ->sortBy(['order_index', 'id'])
            ->values()
            ->map(fn ($p) => [
                'id' => $p->id,
                'team' => $p->team,
                'action' => $p->action,
                'order_index' => $p->order_index,
                'hero' => $p->hero ? [
                    'id' => $p->hero->id,
                    'name' => $p->hero->name,
                    'icon_url' => $p->hero->icon_url,
                ] : null,
            ]);

        return [
            'match' => [
                'id' => $match->id,
                'match_date' => $match->match_date?->format('Y-m-d'),
                'team_a_name' => $match->team_a_name,
                'team_b_name' => $match->team_b_name,
                'winner' => $match->winner,
                'patch' => $match->patch ? [
                    'id' => $match->patch->id,
                    'version' => $match->patch->version,
                    'name' => $match->patch->name,
                ] : null,
            ],
            'draft' => $picks,
            'rosters' => [
                'team_a' => $this->rosterSummary($match, 'team_a'),
                'team_b' => $this->rosterSummary($match, 'team_b'),
            ],
        ];
    }

    private function rosterSummary(GameMatch $match, string $team): array
    {
        return $match->matchPlayers
            ->where('team', $team)
            ->values()
            ->map(fn ($mp) => [
                'player' => $mp->player?->username,
                'hero' => $mp->hero?->name,
                'hero_icon' => $mp->hero?->icon_url,
                'role' => $mp->role?->name,
                'result' => $mp->result,
            ])
            ->toArray();
    }

    private function accumulatePair(array &$store, int $keyA, int $keyB, bool $win): void
    {
        $k = $keyA.'|'.$keyB;
        if (! isset($store[$k])) {
            $store[$k] = ['n' => 0, 'w' => 0];
        }
        $store[$k]['n']++;
        if ($win) {
            $store[$k]['w']++;
        }
    }
}
