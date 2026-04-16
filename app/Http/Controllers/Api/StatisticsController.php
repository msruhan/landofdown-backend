<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StatisticsService;
use Illuminate\Http\JsonResponse;

class StatisticsController extends Controller
{
    public function __construct(
        private readonly StatisticsService $statsService,
    ) {}

    public function dashboard(): JsonResponse
    {
        return response()->json($this->statsService->dashboard());
    }

    public function playerStats(int $id): JsonResponse
    {
        return response()->json($this->statsService->playerStats($id));
    }

    public function heroStats(): JsonResponse
    {
        return response()->json($this->statsService->heroStats());
    }

    public function roleStats(): JsonResponse
    {
        return response()->json($this->statsService->roleStats());
    }

    public function trends(): JsonResponse
    {
        return response()->json($this->statsService->trends());
    }
}
