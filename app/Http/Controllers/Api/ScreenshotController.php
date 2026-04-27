<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GameMatch;
use App\Models\MatchPlayer;
use App\Models\UploadedScreenshot;
use App\Services\CloudinaryUploader;
use App\Services\ScreenshotAiParserService;
use App\Services\ScreenshotOcrService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ScreenshotController extends Controller
{
    public function __construct(
        private readonly ScreenshotOcrService $ocrService,
        private readonly ScreenshotAiParserService $aiParserService,
        private readonly CloudinaryUploader $cloudinaryUploader,
    )
    {
    }

    public function upload(Request $request): JsonResponse
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }

        $request->validate([
            'screenshot' => 'required|image|max:10240',
        ]);

        $uploadedFile = $request->file('screenshot');
        $tmpPath = $uploadedFile->getRealPath();
        if (!$tmpPath) {
            return response()->json(['message' => 'Failed reading uploaded file'], 422);
        }

        $storedPath = $uploadedFile->store('screenshots', 'public');
        $finalPath = $storedPath;

        if ($this->cloudinaryUploader->isConfigured()) {
            $uploadResult = $this->cloudinaryUploader->uploadScreenshot($uploadedFile);
            $finalPath = $uploadResult['file_path'];

            // Keep local disk tidy when Cloudinary is the source of truth.
            if ($storedPath) {
                Storage::disk('public')->delete($storedPath);
            }
        }

        $screenshot = UploadedScreenshot::create([
            'file_path' => $finalPath,
            'status' => 'pending',
            'uploaded_by' => $request->user()->id,
        ]);

        // Prefer AI parser for richer extraction; fallback to OCR if AI fails.
        $aiResult = $this->aiParserService->parse($tmpPath);
        $ocr = $aiResult['success'] ? $aiResult : $this->ocrService->parse($tmpPath);

        $parsedPayload = array_merge([
            'match_date' => now()->toDateString(),
            'team_a_name' => 'Team A',
            'team_b_name' => 'Team B',
            'winner' => 'team_a',
            'duration' => null,
            'notes' => null,
            'players' => [],
        ], $ocr['parsed']);

        $parsedPayload = $this->aiParserService->enrichHeroes($tmpPath, $parsedPayload);

        $screenshot->update([
            'status' => $ocr['success'] ? 'parsed' : 'failed',
            'parsed_data' => [
                'ocr_message' => $ocr['message'],
                'ocr_text' => $ocr['text'],
                'parser' => $aiResult['success'] ? 'ai_openai' : 'ocr_tesseract',
                'suggested' => $parsedPayload,
            ],
        ]);

        return response()->json([
            'data' => $parsedPayload,
            'screenshot_id' => $screenshot->id,
            'file_path' => $screenshot->file_path,
            'ocr_message' => $ocr['message'],
            'ocr_success' => $ocr['success'],
        ], 201);
    }

    public function confirmParsed(Request $request, int $id): JsonResponse
    {
        $screenshot = UploadedScreenshot::findOrFail($id);

        $validated = $request->validate([
            'match_date' => 'required|date',
            'duration' => 'nullable|string|max:10',
            'team_a_name' => 'nullable|string|max:255',
            'team_b_name' => 'nullable|string|max:255',
            'winner' => 'required|in:team_a,team_b',
            'notes' => 'nullable|string',
            'players' => 'required|array|size:10',
            'players.*.player_id' => 'required|exists:players,id',
            'players.*.team' => 'required|in:team_a,team_b',
            'players.*.hero_id' => 'required|exists:heroes,id',
            'players.*.role_id' => 'required|exists:roles,id',
            'players.*.kills' => 'required|integer|min:0',
            'players.*.deaths' => 'required|integer|min:0',
            'players.*.assists' => 'required|integer|min:0',
            'players.*.rating' => 'nullable|numeric|min:0|max:20',
            'players.*.medal' => 'nullable|in:mvp_win,mvp_lose,gold,silver,bronze',
        ]);

        $match = DB::transaction(function () use ($validated, $screenshot, $request) {
            $match = GameMatch::create([
                'match_date' => $validated['match_date'],
                'duration' => $validated['duration'] ?? null,
                'team_a_name' => $validated['team_a_name'] ?? 'Team A',
                'team_b_name' => $validated['team_b_name'] ?? 'Team B',
                'winner' => $validated['winner'],
                'notes' => $validated['notes'] ?? null,
                'screenshot_path' => $screenshot->file_path,
                'created_by' => $request->user()->id,
            ]);

            foreach ($validated['players'] as $playerData) {
                $result = ($playerData['team'] === $validated['winner']) ? 'win' : 'lose';

                MatchPlayer::create([
                    'match_id' => $match->id,
                    'player_id' => $playerData['player_id'],
                    'team' => $playerData['team'],
                    'hero_id' => $playerData['hero_id'],
                    'role_id' => $playerData['role_id'],
                    'kills' => $playerData['kills'],
                    'deaths' => $playerData['deaths'],
                    'assists' => $playerData['assists'],
                    'rating' => $playerData['rating'] ?? null,
                    'medal' => $playerData['medal'] ?? null,
                    'result' => $result,
                ]);
            }

            $screenshot->update([
                'match_id' => $match->id,
                'parsed_data' => $validated,
                'status' => 'reviewed',
            ]);

            return $match;
        });

        $match->load([
            'matchPlayers.player',
            'matchPlayers.hero',
            'matchPlayers.role',
        ]);

        return response()->json([
            'match' => $match,
            'screenshot' => $screenshot->fresh(),
        ], 201);
    }
}
