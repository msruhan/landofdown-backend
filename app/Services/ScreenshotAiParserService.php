<?php

namespace App\Services;

use App\Models\Hero;
use Illuminate\Support\Facades\Http;
use Throwable;

class ScreenshotAiParserService
{
    public function isConfigured(): bool
    {
        return (string) config('services.openai.api_key') !== '';
    }

    public function parse(string $absoluteImagePath): array
    {
        if (!is_file($absoluteImagePath)) {
            return [
                'success' => false,
                'message' => 'Screenshot file not found',
                'text' => '',
                'parsed' => [],
            ];
        }

        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'OpenAI API key is not configured',
                'text' => '',
                'parsed' => [],
            ];
        }

        try {
            $imageData = file_get_contents($absoluteImagePath);
            if ($imageData === false) {
                return [
                    'success' => false,
                    'message' => 'Failed reading screenshot file bytes',
                    'text' => '',
                    'parsed' => [],
                ];
            }

            $mimeType = $this->guessMimeType($absoluteImagePath);
            $dataUrl = 'data:'.$mimeType.';base64,'.base64_encode($imageData);

            $prompt = <<<'TXT'
You are an MLBB match screenshot parser.
Analyze the scoreboard screenshot and return ONLY valid JSON (no markdown fences) with this exact schema:
{
  "match_date": "YYYY-MM-DD or null",
  "team_a_name": "string",
  "team_b_name": "string",
  "winner": "team_a or team_b",
  "duration": "MM:SS or null",
  "notes": "string or null",
  "players": [
    {
      "team": "team_a or team_b",
      "player_name": "string",
      "hero_name": "string or null",
      "lane": "jungle or exp or mid or gold or roam or null",
      "kills": 0,
      "deaths": 0,
      "assists": 0,
      "rating": 0.0,
      "medal": "mvp_win or mvp_lose or gold or silver or bronze or null"
    }
  ]
}

Rules:
- Parse up to 10 players total (5 each side) if visible.
- Use integers for kills/deaths/assists.
- Rating must be numeric (e.g. 7.2) or null if unreadable.
- If winner unknown, infer from score/label; default to "team_a" if uncertain.
- Keep player_name exactly as seen as much as possible.
- Parse hero_name from the row if visible.
- Infer lane from hero or map position if possible.
- If medal can't be read, set null.
- If team names are unknown, use "Team A" and "Team B".
TXT;

            $apiKey = (string) config('services.openai.api_key');
            $model = (string) config('services.openai.vision_model', config('services.openai.model', 'gpt-4o-mini'));
            $openaiBase = (string) config('services.openai.base_url', 'https://api.openai.com/v1');
            $chatUrl = rtrim($openaiBase, '/').'/chat/completions';

            $response = Http::timeout(90)
                ->withToken($apiKey)
                ->post($chatUrl, [
                    'model' => $model,
                    'temperature' => 0.1,
                    'max_tokens' => 1200,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => [
                                ['type' => 'text', 'text' => $prompt],
                                ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]],
                            ],
                        ],
                    ],
                ]);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'message' => 'OpenAI screenshot parsing failed',
                    'text' => '',
                    'parsed' => [],
                    'details' => $response->json(),
                ];
            }

            $text = (string) data_get($response->json(), 'choices.0.message.content', '');
            if (trim($text) === '') {
                return [
                    'success' => false,
                    'message' => 'OpenAI returned empty content',
                    'text' => '',
                    'parsed' => [],
                ];
            }

            $decoded = $this->decodeJsonFromText($text);
            if (!is_array($decoded)) {
                return [
                    'success' => false,
                    'message' => 'OpenAI returned invalid JSON',
                    'text' => $text,
                    'parsed' => [],
                ];
            }

            $normalized = $this->normalizeParsedPayload($decoded);

            return [
                'success' => true,
                'message' => 'AI parsed screenshot successfully',
                'text' => $text,
                'parsed' => $normalized,
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => 'AI parsing error: '.$e->getMessage(),
                'text' => '',
                'parsed' => [],
            ];
        }
    }

    public function enrichHeroes(string $absoluteImagePath, array $parsedPayload): array
    {
        if (!is_file($absoluteImagePath) || !$this->isConfigured()) {
            return $parsedPayload;
        }

        $players = $parsedPayload['players'] ?? null;
        if (!is_array($players) || $players === []) {
            return $parsedPayload;
        }

        $needsEnrichment = false;
        foreach ($players as $player) {
            if (!is_array($player)) {
                continue;
            }
            if (!isset($player['hero_name']) || !is_string($player['hero_name']) || trim($player['hero_name']) === '') {
                $needsEnrichment = true;
                break;
            }
        }

        if (!$needsEnrichment) {
            return $parsedPayload;
        }

        try {
            $imageData = file_get_contents($absoluteImagePath);
            if ($imageData === false) {
                return $parsedPayload;
            }

            $mimeType = $this->guessMimeType($absoluteImagePath);
            $dataUrl = 'data:'.$mimeType.';base64,'.base64_encode($imageData);
            $apiKey = (string) config('services.openai.api_key');
            $model = (string) config('services.openai.vision_model', config('services.openai.model', 'gpt-4o-mini'));
            $openaiBase = (string) config('services.openai.base_url', 'https://api.openai.com/v1');
            $chatUrl = rtrim($openaiBase, '/').'/chat/completions';

            $heroCatalog = Hero::query()->orderBy('name')->pluck('name')->all();
            $playerSkeleton = array_values(array_map(function ($player): array {
                if (!is_array($player)) {
                    return [];
                }

                return [
                    'team' => (($player['team'] ?? 'team_a') === 'team_b') ? 'team_b' : 'team_a',
                    'player_name' => (string) ($player['player_name'] ?? ''),
                    'hero_name' => isset($player['hero_name']) ? (string) $player['hero_name'] : null,
                    'lane' => isset($player['lane']) ? (string) $player['lane'] : null,
                ];
            }, $players));

            $prompt = "Task: identify MLBB hero names from hero portraits/icons in scoreboard.\n".
                "Return ONLY JSON object with schema:\n".
                "{ \"players\": [{\"team\":\"team_a|team_b\", \"player_name\":\"string\", \"hero_name\":\"string|null\", \"lane\":\"jungle|exp|mid|gold|roam|null\"}] }\n".
                "Rules:\n".
                "- Keep player_name exactly from input list.\n".
                "- Choose hero_name ONLY from allowed hero catalog.\n".
                "- If uncertain, set hero_name=null.\n".
                "- Do not invent extra players.\n".
                "- Use lane null if unknown.\n\n".
                "Input players:\n".json_encode($playerSkeleton, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n".
                "Allowed hero catalog:\n".json_encode($heroCatalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $response = Http::timeout(90)
                ->withToken($apiKey)
                ->post($chatUrl, [
                    'model' => $model,
                    'temperature' => 0,
                    'max_tokens' => 900,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => [
                                ['type' => 'text', 'text' => $prompt],
                                ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]],
                            ],
                        ],
                    ],
                ]);

            if (!$response->successful()) {
                return $parsedPayload;
            }

            $text = (string) data_get($response->json(), 'choices.0.message.content', '');
            $decoded = $this->decodeJsonFromText($text);
            if (!is_array($decoded) || !isset($decoded['players']) || !is_array($decoded['players'])) {
                return $parsedPayload;
            }

            $heroByPlayerKey = [];
            $laneByPlayerKey = [];
            foreach ($decoded['players'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $key = $this->normalizeKey((string) ($item['player_name'] ?? ''));
                if ($key === '') {
                    continue;
                }
                $heroByPlayerKey[$key] = $this->normalizeStringOrNull($item['hero_name'] ?? null);
                $laneByPlayerKey[$key] = $this->normalizeLane($item['lane'] ?? null);
            }

            foreach ($parsedPayload['players'] as &$player) {
                if (!is_array($player)) {
                    continue;
                }
                $key = $this->normalizeKey((string) ($player['player_name'] ?? ''));
                if ($key === '') {
                    continue;
                }

                if ((!isset($player['hero_name']) || !is_string($player['hero_name']) || trim($player['hero_name']) === '')
                    && isset($heroByPlayerKey[$key])) {
                    $player['hero_name'] = $heroByPlayerKey[$key];
                }

                if ((!isset($player['lane']) || !is_string($player['lane']) || trim($player['lane']) === '')
                    && isset($laneByPlayerKey[$key])) {
                    $player['lane'] = $laneByPlayerKey[$key];
                }
            }
            unset($player);
        } catch (Throwable) {
            return $parsedPayload;
        }

        return $parsedPayload;
    }

    private function guessMimeType(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'image/jpeg',
        };
    }

    private function decodeJsonFromText(string $text): ?array
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

    private function normalizeParsedPayload(array $payload): array
    {
        $winner = ($payload['winner'] ?? 'team_a') === 'team_b' ? 'team_b' : 'team_a';

        $players = [];
        if (isset($payload['players']) && is_array($payload['players'])) {
            foreach (array_slice($payload['players'], 0, 10) as $p) {
                if (!is_array($p)) {
                    continue;
                }
                $team = ($p['team'] ?? 'team_a') === 'team_b' ? 'team_b' : 'team_a';
                $players[] = [
                    'team' => $team,
                    'player_name' => trim((string) ($p['player_name'] ?? '')),
                    'hero_name' => $this->normalizeStringOrNull($p['hero_name'] ?? null),
                    'lane' => $this->normalizeLane($p['lane'] ?? null),
                    'kills' => max(0, (int) ($p['kills'] ?? 0)),
                    'deaths' => max(0, (int) ($p['deaths'] ?? 0)),
                    'assists' => max(0, (int) ($p['assists'] ?? 0)),
                    'rating' => isset($p['rating']) && is_numeric($p['rating']) ? (float) $p['rating'] : null,
                    'medal' => $this->normalizeMedal($p['medal'] ?? null),
                ];
            }
        }

        return [
            'match_date' => $this->normalizeDate($payload['match_date'] ?? null),
            'team_a_name' => trim((string) ($payload['team_a_name'] ?? 'Team A')) ?: 'Team A',
            'team_b_name' => trim((string) ($payload['team_b_name'] ?? 'Team B')) ?: 'Team B',
            'winner' => $winner,
            'duration' => $this->normalizeDuration($payload['duration'] ?? null),
            'notes' => isset($payload['notes']) ? (string) $payload['notes'] : null,
            'players' => $players,
        ];
    }

    private function normalizeDate(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $date = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
            return $date;
        }

        return null;
    }

    private function normalizeDuration(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $duration = trim($value);
        if (preg_match('/^\d{1,2}:\d{2}$/', $duration) === 1) {
            return $duration;
        }

        return null;
    }

    private function normalizeMedal(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $val = strtolower(trim($value));
        if (in_array($val, ['mvp_win', 'mvp_lose', 'gold', 'silver', 'bronze'], true)) {
            return $val;
        }
        if ($val === 'mvp') {
            return 'mvp_win';
        }

        return null;
    }

    private function normalizeStringOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function normalizeLane(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $lane = strtolower(trim($value));

        return in_array($lane, ['jungle', 'exp', 'mid', 'gold', 'roam'], true) ? $lane : null;
    }

    private function normalizeKey(string $value): string
    {
        $lower = strtolower($value);

        return preg_replace('/[^a-z0-9]/', '', $lower) ?? '';
    }
}
