<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StatisticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StatisticsController extends Controller
{
    public function __construct(
        private readonly StatisticsService $statsService,
    ) {}

    public function dashboard(Request $request): JsonResponse
    {
        return response()->json($this->statsService->dashboard($request));
    }

    public function playerStats(Request $request, int $id): JsonResponse
    {
        return response()->json($this->statsService->playerStats($id, $request));
    }

    public function heroStats(Request $request): JsonResponse
    {
        return response()->json($this->statsService->heroStats($request));
    }

    public function roleStats(Request $request): JsonResponse
    {
        return response()->json($this->statsService->roleStats($request));
    }

    public function trends(Request $request): JsonResponse
    {
        return response()->json($this->statsService->trends($request));
    }

    public function synergy(Request $request): JsonResponse
    {
        return response()->json($this->statsService->synergy($request));
    }
}
