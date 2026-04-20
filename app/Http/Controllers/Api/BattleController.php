<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class BattleController extends Controller
{
    /**
     * Generate AI-assisted battle lanes using OpenRouter (OpenAI-compatible).
     *
     * @throws ValidationException
     */
    public function aiRandomize(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'prompt' => 'required|string|min:3|max:2000',
            'team_a_name' => 'nullable|string|max:120',
            'team_b_name' => 'nullable|string|max:120',
            'players' => 'required|array|min:10|max:50',
            'players.*.id' => 'required|integer',
            'players.*.username' => 'required|string|max:255',
            'current_slots' => 'sometimes|array|size:5',
            'current_slots.*.lane' => 'required_with:current_slots|in:jungle,exp,mid,gold,roam',
            'current_slots.*.team_a_player_id' => 'required_with:current_slots|integer',
            'current_slots.*.team_b_player_id' => 'required_with:current_slots|integer',
            'instruction_history' => 'sometimes|array|max:20',
            'instruction_history.*' => 'string|max:500',
        ]);

        $apiKey = (string) config('services.openrouter.api_key');
        if ($apiKey === '') {
            return response()->json([
                'message' => 'OpenRouter API key is not configured on server.',
            ], 503);
        }

        $model = (string) config('services.openrouter.model', 'openai/gpt-4o-mini');
        $lanes = ['jungle', 'exp', 'mid', 'gold', 'roam'];

        $players = collect($validated['players'])
            ->map(fn ($p) => ['id' => (int) $p['id'], 'username' => (string) $p['username']])
            ->unique('id')
            ->values();

        if ($players->count() < 10) {
            return response()->json(['message' => 'At least 10 unique players are required.'], 422);
        }

        $systemInstruction = <<<'TXT'
You are an esports draft assistant for MLBB.
Return ONLY valid JSON, without markdown fences.
Output schema:
{
  "team_a_name": "string",
  "team_b_name": "string",
  "slots": [
    {"lane":"jungle","team_a_player_id":1,"team_b_player_id":2},
    {"lane":"exp","team_a_player_id":3,"team_b_player_id":4},
    {"lane":"mid","team_a_player_id":5,"team_b_player_id":6},
    {"lane":"gold","team_a_player_id":7,"team_b_player_id":8},
    {"lane":"roam","team_a_player_id":9,"team_b_player_id":10}
  ]
}
Rules:
- Exactly 5 slots, one per lane (jungle, exp, mid, gold, roam).
- Exactly 10 unique player ids overall.
- Use only ids from provided player list.
- Respect user constraints in prompt as much as possible.
- If constraint is ambiguous, make the best reasonable guess.
- If current_slots is provided, treat it as existing draft memory and adjust it instead of fully resetting, unless user asks to reroll.
TXT;

        $userPayload = [
            'prompt' => $validated['prompt'],
            'team_a_name' => $validated['team_a_name'] ?? 'Team Alpha',
            'team_b_name' => $validated['team_b_name'] ?? 'Team Omega',
            'allowed_lanes' => $lanes,
            'players' => $players->values()->all(),
            'current_slots' => $validated['current_slots'] ?? null,
            'instruction_history' => $validated['instruction_history'] ?? [],
        ];

        $response = Http::timeout(45)
            ->withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'HTTP-Referer' => (string) config('app.url', 'http://localhost'),
                'X-Title' => 'MLBB Stats Battle AI',
            ])
            ->post('https://openrouter.ai/api/v1/chat/completions', [
                'model' => $model,
                'response_format' => [
                    'type' => 'json_object',
                ],
                'temperature' => 0.3,
                'messages' => [
                    ['role' => 'system', 'content' => $systemInstruction],
                    ['role' => 'user', 'content' => "Input:\n".json_encode($userPayload, JSON_PRETTY_PRINT)],
                ],
            ]);

        if (!$response->successful()) {
            return response()->json([
                'message' => 'OpenRouter request failed.',
                'details' => $response->json(),
            ], 502);
        }

        $text = data_get($response->json(), 'choices.0.message.content');
        if (!is_string($text) || trim($text) === '') {
            return response()->json([
                'message' => 'OpenRouter response is empty.',
            ], 502);
        }

        $decoded = $this->decodeJsonFromText($text);
        if (!is_array($decoded)) {
            return response()->json([
                'message' => 'OpenRouter response is not valid JSON.',
                'raw' => $text,
            ], 502);
        }

        $validationError = $this->validateAiResult($decoded, $players->pluck('id')->all(), $lanes);
        if ($validationError !== null) {
            return response()->json([
                'message' => "Invalid AI draft result: {$validationError}",
                'result' => $decoded,
            ], 422);
        }

        return response()->json([
            'team_a_name' => (string) ($decoded['team_a_name'] ?? ($validated['team_a_name'] ?? 'Team Alpha')),
            'team_b_name' => (string) ($decoded['team_b_name'] ?? ($validated['team_b_name'] ?? 'Team Omega')),
            'slots' => $decoded['slots'],
        ]);
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

    /**
     * @param array<int, int> $allowedPlayerIds
     * @param array<int, string> $lanes
     */
    private function validateAiResult(array $result, array $allowedPlayerIds, array $lanes): ?string
    {
        if (!isset($result['slots']) || !is_array($result['slots'])) {
            return 'slots must be an array';
        }

        if (count($result['slots']) !== 5) {
            return 'slots must contain exactly 5 entries';
        }

        $laneSeen = [];
        $playerSeen = [];
        $allowedIdMap = array_flip($allowedPlayerIds);
        $laneMap = array_flip($lanes);

        foreach ($result['slots'] as $idx => $slot) {
            if (!is_array($slot)) {
                return "slot {$idx} must be an object";
            }

            $lane = $slot['lane'] ?? null;
            $a = $slot['team_a_player_id'] ?? null;
            $b = $slot['team_b_player_id'] ?? null;

            if (!is_string($lane) || !isset($laneMap[$lane])) {
                return "slot {$idx} has invalid lane";
            }
            if (!is_int($a) || !is_int($b)) {
                return "slot {$idx} player ids must be integers";
            }
            if (!isset($allowedIdMap[$a]) || !isset($allowedIdMap[$b])) {
                return "slot {$idx} contains unknown player id";
            }
            if ($a === $b) {
                return "slot {$idx} has duplicate player in both teams";
            }
            if (isset($laneSeen[$lane])) {
                return "lane {$lane} is duplicated";
            }
            $laneSeen[$lane] = true;

            if (isset($playerSeen[$a]) || isset($playerSeen[$b])) {
                return "player id is duplicated across slots";
            }
            $playerSeen[$a] = true;
            $playerSeen[$b] = true;
        }

        if (count($playerSeen) !== 10) {
            return 'result must contain exactly 10 unique players';
        }

        return null;
    }
}

