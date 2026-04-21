<?php

namespace Database\Seeders;

use App\Models\DraftPick;
use App\Models\GameMatch;
use App\Models\Hero;
use App\Models\MatchPlayer;
use App\Models\Patch;
use App\Models\Player;
use App\Models\Role;
use Illuminate\Database\Seeder;

class DemoMatchSeeder extends Seeder
{
    public function run(): void
    {
        // In-game style usernames (MLBB result screen vibe: lowercase, numbers, underscores)
        $playerNames = [
            'wolay70', 'Leadership', 'Bluerose_magic', 'zandal', 'Laperauss',
            'Nuas', 'Drenvic', 'balabalahard', 'G.O.Y', 'Avalanch',
        ];

        // Skill tiers: first 3 are "strong" players, next 4 are "average", last 3 are "weaker"
        $skillTier = [];
        foreach ($playerNames as $i => $name) {
            $player = Player::firstOrCreate(
                ['username' => $name],
                ['avatar_url' => "https://api.dicebear.com/7.x/adventurer/svg?seed={$name}"]
            );
            if (!$player->avatar_url) {
                $player->update(['avatar_url' => "https://api.dicebear.com/7.x/adventurer/svg?seed={$name}"]);
            }
            $skillTier[$player->id] = match (true) {
                $i < 3 => 'strong',
                $i < 7 => 'average',
                default => 'weak',
            };
        }

        $players = Player::whereIn('username', $playerNames)->get();
        $heroes = Hero::with('role')->get();
        $roles = Role::all();
        $patches = Patch::orderBy('release_date')->get();
        for ($m = 0; $m < 20; $m++) {
            $matchDate = now()->subDays(rand(0, 60));
            $minutes = rand(10, 25);
            $seconds = rand(0, 59);
            $duration = sprintf('%d:%02d', $minutes, $seconds);
            $winner = rand(0, 1) ? 'team_a' : 'team_b';

            $patchForMatch = $this->resolvePatch($patches, $matchDate);

            $match = GameMatch::create([
                'match_date' => $matchDate->format('Y-m-d'),
                'duration' => $duration,
                'team_a_name' => 'Team A',
                'team_b_name' => 'Team B',
                'winner' => $winner,
                'patch_id' => $patchForMatch?->id,
            ]);

            $shuffled = $players->shuffle();
            $teamA = $shuffled->take(5)->values();
            $teamB = $shuffled->skip(5)->take(5)->values();

            $usedHeroes = [];
            $allMatchPlayers = [];

            foreach (['team_a' => $teamA, 'team_b' => $teamB] as $team => $teamPlayers) {
                $teamRoles = $roles->shuffle()->take(5)->values();

                foreach ($teamPlayers as $idx => $player) {
                    $role = $teamRoles[$idx];
                    $roleHeroes = $heroes->where('role_id', $role->id)
                        ->whereNotIn('id', $usedHeroes);

                    if ($roleHeroes->isEmpty()) {
                        $roleHeroes = $heroes->whereNotIn('id', $usedHeroes);
                    }

                    $hero = $roleHeroes->random();
                    $usedHeroes[] = $hero->id;

                    $result = ($team === $winner) ? 'win' : 'lose';

                    $tier = $skillTier[$player->id] ?? 'average';
                    [$kills, $deaths, $assists, $rating] = $this->generateKda($tier, $result);

                    $allMatchPlayers[] = [
                        'match_id' => $match->id,
                        'player_id' => $player->id,
                        'team' => $team,
                        'hero_id' => $hero->id,
                        'role_id' => $role->id,
                        'kills' => $kills,
                        'deaths' => $deaths,
                        'assists' => $assists,
                        'rating' => $rating,
                        'medal' => null,
                        'result' => $result,
                    ];
                }
            }

            // Assign medals: highest rating in the match gets MVP, then gold/silver/bronze
            usort($allMatchPlayers, fn ($a, $b) => $b['rating'] <=> $a['rating']);
            foreach ($allMatchPlayers as $i => &$mp) {
                if ($i === 0) {
                    $mp['medal'] = $mp['result'] === 'win' ? 'mvp_win' : 'mvp_lose';
                } elseif ($i <= 2) {
                    $mp['medal'] = 'gold';
                } elseif ($i <= 5) {
                    $mp['medal'] = 'silver';
                } else {
                    $mp['medal'] = 'bronze';
                }
            }
            unset($mp);

            foreach ($allMatchPlayers as $mpData) {
                MatchPlayer::create($mpData);
            }

            $this->seedDraftPicks($match, $allMatchPlayers, $heroes);
        }
    }

    private function resolvePatch($patches, $matchDate): ?Patch
    {
        if ($patches->isEmpty()) {
            return null;
        }

        return $patches
            ->filter(fn ($p) => $p->release_date <= $matchDate)
            ->sortByDesc('release_date')
            ->first() ?? $patches->first();
    }

    private function seedDraftPicks(GameMatch $match, array $matchPlayers, $heroes): void
    {
        $pickedHeroIds = collect($matchPlayers)->pluck('hero_id')->all();
        $bannedPool = $heroes->whereNotIn('id', $pickedHeroIds)->pluck('id')->shuffle()->take(6)->values();

        $teamAPicks = collect($matchPlayers)->where('team', 'team_a')->values();
        $teamBPicks = collect($matchPlayers)->where('team', 'team_b')->values();

        $order = 1;
        foreach ($bannedPool as $idx => $heroId) {
            DraftPick::create([
                'match_id' => $match->id,
                'team' => $idx % 2 === 0 ? 'team_a' : 'team_b',
                'action' => 'ban',
                'order_index' => $order++,
                'hero_id' => $heroId,
            ]);
        }

        $pickSequence = [
            ['team_a', 0], ['team_b', 0], ['team_b', 1],
            ['team_a', 1], ['team_a', 2], ['team_b', 2],
            ['team_b', 3], ['team_a', 3], ['team_a', 4], ['team_b', 4],
        ];

        foreach ($pickSequence as [$team, $slot]) {
            $source = $team === 'team_a' ? $teamAPicks : $teamBPicks;
            $mp = $source[$slot] ?? null;
            if (! $mp) {
                continue;
            }

            DraftPick::create([
                'match_id' => $match->id,
                'team' => $team,
                'action' => 'pick',
                'order_index' => $order++,
                'hero_id' => $mp['hero_id'],
            ]);
        }
    }

    private function generateKda(string $tier, string $result): array
    {
        $isWin = $result === 'win';

        [$killBase, $deathBase, $assistBase, $ratingBase] = match ($tier) {
            'strong' => [
                $isWin ? rand(6, 14) : rand(3, 8),
                $isWin ? rand(1, 4) : rand(3, 7),
                $isWin ? rand(5, 12) : rand(3, 8),
                $isWin ? round(rand(80, 120) / 10, 1) : round(rand(60, 85) / 10, 1),
            ],
            'average' => [
                $isWin ? rand(3, 10) : rand(2, 6),
                $isWin ? rand(2, 6) : rand(4, 9),
                $isWin ? rand(4, 10) : rand(2, 7),
                $isWin ? round(rand(70, 100) / 10, 1) : round(rand(55, 78) / 10, 1),
            ],
            default => [
                $isWin ? rand(1, 7) : rand(0, 4),
                $isWin ? rand(3, 8) : rand(5, 11),
                $isWin ? rand(3, 8) : rand(1, 5),
                $isWin ? round(rand(60, 85) / 10, 1) : round(rand(50, 70) / 10, 1),
            ],
        };

        return [$killBase, $deathBase, $assistBase, $ratingBase];
    }
}
