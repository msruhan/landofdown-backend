<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BattleController;
use App\Http\Controllers\Api\DraftController;
use App\Http\Controllers\Api\HeadToHeadController;
use App\Http\Controllers\Api\HeroController;
use App\Http\Controllers\Api\MabarController;
use App\Http\Controllers\Api\MabarRoomController;
use App\Http\Controllers\Api\MatchController;
use App\Http\Controllers\Api\PatchController;
use App\Http\Controllers\Api\PlayerController;
use App\Http\Controllers\Api\PredictionController;
use App\Http\Controllers\Api\RankingController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\ScreenshotController;
use App\Http\Controllers\Api\StatisticsController;
use Illuminate\Support\Facades\Route;

// Public auth
Route::post('/admin/login', [AuthController::class, 'login']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/register', [AuthController::class, 'register']);

// Public statistics
Route::get('/statistics/dashboard', [StatisticsController::class, 'dashboard']);
Route::get('/statistics/players/{id}', [StatisticsController::class, 'playerStats']);
Route::get('/statistics/heroes', [StatisticsController::class, 'heroStats']);
Route::get('/statistics/roles', [StatisticsController::class, 'roleStats']);
Route::get('/statistics/trends', [StatisticsController::class, 'trends']);
Route::get('/statistics/synergy', [StatisticsController::class, 'synergy']);

// Public rankings
Route::get('/rankings', [RankingController::class, 'index']);

// Public resource reads
Route::get('/players', [PlayerController::class, 'index']);
Route::get('/players/{id}', [PlayerController::class, 'show']);
Route::get('/heroes', [HeroController::class, 'index']);
Route::get('/heroes/{id}', [HeroController::class, 'show']);
Route::get('/roles', [RoleController::class, 'index']);
Route::get('/matches', [MatchController::class, 'index']);
Route::get('/matches/{id}', [MatchController::class, 'show']);
Route::get('/matches/{id}/screenshot', [MatchController::class, 'screenshot']);
Route::post('/battle/ai-randomize', [BattleController::class, 'aiRandomize']);

// Draft Analysis (Pick/Ban)
Route::get('/drafts/overview', [DraftController::class, 'overview']);
Route::get('/drafts/synergy', [DraftController::class, 'synergy']);
Route::get('/drafts/counters', [DraftController::class, 'counters']);
Route::post('/drafts/recommend', [DraftController::class, 'recommend']);
Route::get('/drafts/match/{matchId}', [DraftController::class, 'matchDraft']);

// Meta Tracker (Patches)
Route::get('/patches', [PatchController::class, 'index']);
Route::get('/patches/meta', [PatchController::class, 'meta']);
Route::get('/patches/{id}', [PatchController::class, 'show']);

// Head-to-Head
Route::get('/head-to-head/players', [HeadToHeadController::class, 'players']);
Route::post('/head-to-head/teams', [HeadToHeadController::class, 'teams']);

// Win Prediction
Route::post('/prediction/predict', [PredictionController::class, 'predict']);
Route::post('/prediction/reasoning', [PredictionController::class, 'reasoning']);

// Authenticated (member + admin) routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    // Mabar Lounge
    Route::get('/mabar/sessions', [MabarController::class, 'index']);
    Route::get('/mabar/sessions/{id}', [MabarController::class, 'show']);
    Route::post('/mabar/sessions', [MabarController::class, 'store']);
    Route::patch('/mabar/sessions/{id}', [MabarController::class, 'update']);
    Route::delete('/mabar/sessions/{id}', [MabarController::class, 'destroy']);

    Route::post('/mabar/sessions/{id}/join', [MabarController::class, 'join']);
    Route::post('/mabar/sessions/{id}/leave', [MabarController::class, 'leave']);
    Route::post('/mabar/sessions/{id}/slots/{slotId}/approve', [MabarController::class, 'approve']);
    Route::post('/mabar/sessions/{id}/slots/{slotId}/kick', [MabarController::class, 'kick']);
    Route::post('/mabar/sessions/{id}/transition', [MabarController::class, 'transition']);
    Route::post('/mabar/sessions/{id}/feature', [MabarController::class, 'feature']);
    Route::post('/mabar/sessions/{id}/rate', [MabarController::class, 'rate']);

    Route::get('/mabar/ready-now', [MabarController::class, 'readyNow']);
    Route::post('/mabar/ready-now', [MabarController::class, 'setSignal']);
    Route::delete('/mabar/ready-now', [MabarController::class, 'clearSignal']);

    Route::get('/mabar/me', [MabarController::class, 'myStats']);

    // Mabar private room (chat)
    Route::get('/mabar/sessions/{id}/room', [MabarRoomController::class, 'room']);
    Route::get('/mabar/sessions/{id}/messages', [MabarRoomController::class, 'listMessages']);
    Route::post('/mabar/sessions/{id}/messages', [MabarRoomController::class, 'send']);
    Route::post('/mabar/sessions/{id}/messages/{msgId}/react', [MabarRoomController::class, 'react']);
    Route::post('/mabar/sessions/{id}/messages/{msgId}/pin', [MabarRoomController::class, 'togglePin']);
    Route::delete('/mabar/sessions/{id}/messages/{msgId}', [MabarRoomController::class, 'deleteMessage']);
    Route::post('/mabar/sessions/{id}/heartbeat', [MabarRoomController::class, 'heartbeat']);
});

// Admin protected routes
Route::middleware('auth:sanctum')->prefix('admin')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    Route::apiResource('players', PlayerController::class)->except(['index', 'show']);
    Route::apiResource('heroes', HeroController::class)->except(['index', 'show']);
    Route::apiResource('roles', RoleController::class)->except(['index']);
    Route::apiResource('matches', MatchController::class)->except(['index', 'show']);
    Route::apiResource('patches', PatchController::class)->except(['index', 'show']);

    Route::post('/screenshots/upload', [ScreenshotController::class, 'upload']);
    Route::post('/screenshots/{id}/confirm', [ScreenshotController::class, 'confirmParsed']);
});
