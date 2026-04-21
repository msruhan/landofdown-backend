<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BattleController;
use App\Http\Controllers\Api\DraftController;
use App\Http\Controllers\Api\HeadToHeadController;
use App\Http\Controllers\Api\HeroController;
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

// Public statistics
Route::get('/statistics/dashboard', [StatisticsController::class, 'dashboard']);
Route::get('/statistics/players/{id}', [StatisticsController::class, 'playerStats']);
Route::get('/statistics/heroes', [StatisticsController::class, 'heroStats']);
Route::get('/statistics/roles', [StatisticsController::class, 'roleStats']);
Route::get('/statistics/trends', [StatisticsController::class, 'trends']);

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
