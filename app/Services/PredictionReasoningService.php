<?php

namespace App\Services;

use App\Models\MatchPlayer;
use App\Models\Player;
use Illuminate\Support\Facades\Http;

class PredictionReasoningService
{
    /**
     * Build an AI-generated narrative explaining why a team is favored.
     *
     * @param  array{name?: string, players: array<int, array{player_id: int, hero_id?: int|null, role_id?: int|null}>}  $teamA
     * @param  array{name?: string, players: array<int, array{player_id: int, hero_id?: int|null, role_id?: int|null}>}  $teamB
     * @param  array<string, mixed>|null  $prediction
     * @return array{favored_team: string, summary: string, key_factors: array<int, array{type: string, team: string, title: string, description: string}>, model?: string, raw?: string}
     */
    public function generate(array $teamA, array $teamB, ?array $prediction = null): array
    {
        @set_time_limit(90);

        $apiKey = (string) config('services.gemini.api_key');

        $context = [
            'team_a' => [
                'name' => (string) ($teamA['name'] ?? 'Team A'),
                'players' => $this->enrichPlayers($teamA['players'] ?? []),
            ],
            'team_b' => [
                'name' => (string) ($teamB['name'] ?? 'Team B'),
                'players' => $this->enrichPlayers($teamB['players'] ?? []),
            ],
            'prediction' => $prediction,
        ];

        if ($apiKey === '') {
            return $this->fallbackReasoning($context);
        }

        $model = (string) config('services.gemini.model', 'gemini-2.0-flash');

        $systemInstruction = <<<'TXT'
You are a Mobile Legends: Bang Bang esports analyst.
Explain briefly (max 120 words) why one team is favored to win, in Bahasa Indonesia (semi-casual, tone confident but not arrogant).
Return ONLY JSON, no markdown fences.

Schema:
{
  "favored_team": "team_a" | "team_b" | "draw",
  "summary": "string (max 120 words, 2-3 sentences)",
  "key_factors": [
    {
      "type": "form" | "hero_mastery" | "role_mastery" | "overall_winrate" | "matchup" | "synergy",
      "team": "team_a" | "team_b" | "neutral",
      "title": "string (max 40 chars, headline)",
      "description": "string (max 180 chars, supporting data)"
    }
  ]
}

Rules:
- Output 3 to 5 key_factors total (mix of both teams, prioritize strongest signals).
- favored_team MUST match the team with higher win_probability; use "draw" only if gap < 1%.
- Base every factor on concrete numbers from the context (recent form, hero mastery, overall winrate).
- Do not invent players, roles, or stats.
- Do not mention the word "JSON" in the summary. Write naturally.
TXT;

        try {
            $response = Http::timeout(45)
                ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}", [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => $systemInstruction."\n\nContext:\n".json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)],
                            ],
                        ],
                    ],
                    'generationConfig' => [
                        'temperature' => 0.5,
                        'maxOutputTokens' => 1024,
                        'responseMimeType' => 'application/json',
                    ],
                ]);
        } catch (\Throwable $e) {
            return $this->fallbackReasoning($context, $e->getMessage());
        }

        if (! $response->successful()) {
            return $this->fallbackReasoning($context, 'Gemini returned '.$response->status());
        }

        $text = data_get($response->json(), 'candidates.0.content.parts.0.text');
        if (! is_string($text) || trim($text) === '') {
            return $this->fallbackReasoning($context, 'Empty AI response');
        }

        $decoded = $this->decodeJson($text);
        if (! is_array($decoded) || ! isset($decoded['summary'])) {
            return $this->fallbackReasoning($context, 'Malformed AI JSON', $text);
        }

        $favoredTeam = in_array($decoded['favored_team'] ?? null, ['team_a', 'team_b', 'draw'], true)
            ? $decoded['favored_team']
            : $this->deriveFavoredTeam($prediction);

        return [
            'favored_team' => $favoredTeam,
            'summary' => (string) $decoded['summary'],
            'key_factors' => $this->normalizeFactors($decoded['key_factors'] ?? []),
            'model' => $model,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $players
     * @return array<int, array<string, mixed>>
     */
    private function enrichPlayers(array $players): array
    {
        return collect($players)->map(function ($slot) {
            $playerId = isset($slot['player_id']) ? (int) $slot['player_id'] : null;
            if (! $playerId) {
                return null;
            }

            $player = Player::find($playerId);
            $recent = MatchPlayer::where('player_id', $playerId)
                ->orderByDesc('id')
                ->limit(5)
                ->pluck('result');
            $recentWins = $recent->filter(fn ($r) => $r === 'win')->count();

            $totalMatches = MatchPlayer::where('player_id', $playerId)->count();
            $totalWins = MatchPlayer::where('player_id', $playerId)->where('result', 'win')->count();

            $heroStats = null;
            if (! empty($slot['hero_id'])) {
                $heroId = (int) $slot['hero_id'];
                $heroTotal = MatchPlayer::where('player_id', $playerId)->where('hero_id', $heroId)->count();
                $heroWins = MatchPlayer::where('player_id', $playerId)->where('hero_id', $heroId)->where('result', 'win')->count();
                $heroStats = [
                    'matches' => $heroTotal,
                    'wins' => $heroWins,
                    'win_rate' => $heroTotal > 0 ? round(($heroWins / $heroTotal) * 100, 1) : null,
                ];
            }

            return [
                'player_id' => $playerId,
                'username' => $player?->username,
                'recent_form' => [
                    'streak' => $recent->all(),
                    'wins' => $recentWins,
                    'rate' => $recent->count() > 0 ? round(($recentWins / $recent->count()) * 100, 1) : null,
                ],
                'overall' => [
                    'matches' => $totalMatches,
                    'wins' => $totalWins,
                    'win_rate' => $totalMatches > 0 ? round(($totalWins / $totalMatches) * 100, 1) : null,
                ],
                'hero_mastery' => $heroStats,
            ];
        })
            ->filter()
            ->values()
            ->toArray();
    }

    private function decodeJson(string $text): ?array
    {
        $trimmed = trim($text);
        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/s', $trimmed, $matches) === 1) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * @param  mixed  $factors
     * @return array<int, array{type: string, team: string, title: string, description: string}>
     */
    private function normalizeFactors($factors): array
    {
        if (! is_array($factors)) {
            return [];
        }

        $allowedType = ['form', 'hero_mastery', 'role_mastery', 'overall_winrate', 'matchup', 'synergy'];
        $allowedTeam = ['team_a', 'team_b', 'neutral'];

        return collect($factors)
            ->map(function ($f) use ($allowedType, $allowedTeam) {
                if (! is_array($f)) {
                    return null;
                }
                $type = in_array($f['type'] ?? null, $allowedType, true) ? $f['type'] : 'overall_winrate';
                $team = in_array($f['team'] ?? null, $allowedTeam, true) ? $f['team'] : 'neutral';

                $title = trim((string) ($f['title'] ?? ''));
                $description = trim((string) ($f['description'] ?? ''));
                if ($title === '' || $description === '') {
                    return null;
                }

                return [
                    'type' => $type,
                    'team' => $team,
                    'title' => mb_substr($title, 0, 60),
                    'description' => mb_substr($description, 0, 240),
                ];
            })
            ->filter()
            ->take(6)
            ->values()
            ->toArray();
    }

    private function deriveFavoredTeam(?array $prediction): string
    {
        if (! $prediction) {
            return 'draw';
        }

        $probA = (float) data_get($prediction, 'team_a.win_probability', 0);
        $probB = (float) data_get($prediction, 'team_b.win_probability', 0);

        if (abs($probA - $probB) < 1.0) {
            return 'draw';
        }

        return $probA > $probB ? 'team_a' : 'team_b';
    }

    /**
     * Rule-based fallback when AI is not available.
     *
     * @param  array<string, mixed>  $context
     */
    private function fallbackReasoning(array $context, ?string $warning = null, ?string $raw = null): array
    {
        $prediction = $context['prediction'] ?? null;
        $favored = $this->deriveFavoredTeam($prediction);
        $teamAName = (string) data_get($context, 'team_a.name', 'Team A');
        $teamBName = (string) data_get($context, 'team_b.name', 'Team B');
        $probA = (float) data_get($prediction, 'team_a.win_probability', 50);
        $probB = (float) data_get($prediction, 'team_b.win_probability', 50);

        if ($favored === 'team_a') {
            $summary = "{$teamAName} lebih diunggulkan (~{$probA}%) berkat rata-rata form dan winrate yang lebih tinggi dibanding {$teamBName}. Narasi AI tidak tersedia, ini ringkasan berbasis data.";
        } elseif ($favored === 'team_b') {
            $summary = "{$teamBName} lebih diunggulkan (~{$probB}%) karena performa rata-rata pemainnya lebih kuat dari {$teamAName}. Narasi AI tidak tersedia, ini ringkasan berbasis data.";
        } else {
            $summary = "Peluang kedua tim hampir seimbang. Narasi AI tidak tersedia, prediksi ini murni berdasar statistik historis.";
        }

        $factors = [];
        foreach (['team_a', 'team_b'] as $side) {
            $rosters = data_get($context, "{$side}.players", []);
            $topForm = collect($rosters)
                ->sortByDesc(fn ($p) => (float) data_get($p, 'recent_form.rate', 0))
                ->first();
            if ($topForm) {
                $factors[] = [
                    'type' => 'form',
                    'team' => $side,
                    'title' => 'Form terbaik: '.data_get($topForm, 'username'),
                    'description' => data_get($topForm, 'recent_form.wins', 0).'/'.(count(data_get($topForm, 'recent_form.streak', [])) ?: 5).' menang di 5 match terakhir ('.(data_get($topForm, 'recent_form.rate') ?? 0).'%).',
                ];
            }

            $topOverall = collect($rosters)
                ->sortByDesc(fn ($p) => (float) data_get($p, 'overall.win_rate', 0))
                ->first();
            if ($topOverall) {
                $factors[] = [
                    'type' => 'overall_winrate',
                    'team' => $side,
                    'title' => 'Winrate global: '.data_get($topOverall, 'username'),
                    'description' => data_get($topOverall, 'overall.wins', 0).'/'.data_get($topOverall, 'overall.matches', 0).' match ('.(data_get($topOverall, 'overall.win_rate') ?? 0).'% overall winrate).',
                ];
            }
        }

        $result = [
            'favored_team' => $favored,
            'summary' => $summary,
            'key_factors' => $factors,
        ];
        if ($warning) {
            $result['warning'] = $warning;
        }
        if ($raw) {
            $result['raw'] = $raw;
        }

        return $result;
    }
}
