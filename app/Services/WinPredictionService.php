<?php

namespace App\Services;

use App\Models\MatchPlayer;
use App\Models\Player;

class WinPredictionService
{
    private const RECENT_FORM_WEIGHT = 0.35;
    private const HERO_MASTERY_WEIGHT = 0.35;
    private const ROLE_MASTERY_WEIGHT = 0.20;
    private const OVERALL_WEIGHT = 0.10;
    private const SCALE = 1.6;

    public function predict(array $teamA, array $teamB): array
    {
        $scoreA = $this->teamScore($teamA);
        $scoreB = $this->teamScore($teamB);

        $diff = ($scoreA['score'] - $scoreB['score']) * self::SCALE;
        $probA = 1 / (1 + exp(-$diff));
        $probB = 1 - $probA;

        return [
            'team_a' => [
                'score' => round($scoreA['score'], 3),
                'win_probability' => round($probA * 100, 1),
                'players' => $scoreA['players'],
            ],
            'team_b' => [
                'score' => round($scoreB['score'], 3),
                'win_probability' => round($probB * 100, 1),
                'players' => $scoreB['players'],
            ],
            'confidence' => round(abs($probA - 0.5) * 200, 1),
            'weights' => [
                'recent_form' => self::RECENT_FORM_WEIGHT,
                'hero_mastery' => self::HERO_MASTERY_WEIGHT,
                'role_mastery' => self::ROLE_MASTERY_WEIGHT,
                'overall' => self::OVERALL_WEIGHT,
            ],
        ];
    }

    private function teamScore(array $roster): array
    {
        $totalScore = 0.0;
        $details = [];
        $count = 0;

        foreach ($roster as $slot) {
            $playerId = isset($slot['player_id']) ? (int) $slot['player_id'] : null;
            $heroId = isset($slot['hero_id']) ? (int) $slot['hero_id'] : null;
            $roleId = isset($slot['role_id']) ? (int) $slot['role_id'] : null;

            if (! $playerId) {
                continue;
            }

            $detail = $this->playerScore($playerId, $heroId, $roleId);
            $details[] = $detail;
            $totalScore += $detail['score'];
            $count++;
        }

        $avg = $count > 0 ? $totalScore / $count : 0.5;

        return [
            'score' => $avg,
            'players' => $details,
        ];
    }

    private function playerScore(int $playerId, ?int $heroId, ?int $roleId): array
    {
        $player = Player::find($playerId);
        $recentForm = $this->recentForm($playerId);
        $overall = $this->overallWinRate($playerId);
        $heroMastery = $heroId ? $this->heroMastery($playerId, $heroId) : null;
        $roleMastery = $roleId ? $this->roleMastery($playerId, $roleId) : null;

        $weightedTotal = 0.0;
        $weightSum = 0.0;

        $weightedTotal += $recentForm['rate'] * self::RECENT_FORM_WEIGHT;
        $weightSum += self::RECENT_FORM_WEIGHT;

        $weightedTotal += $overall['rate'] * self::OVERALL_WEIGHT;
        $weightSum += self::OVERALL_WEIGHT;

        if ($heroMastery) {
            $weightedTotal += $heroMastery['rate'] * self::HERO_MASTERY_WEIGHT;
            $weightSum += self::HERO_MASTERY_WEIGHT;
        }

        if ($roleMastery) {
            $weightedTotal += $roleMastery['rate'] * self::ROLE_MASTERY_WEIGHT;
            $weightSum += self::ROLE_MASTERY_WEIGHT;
        }

        $score = $weightSum > 0 ? $weightedTotal / $weightSum : 0.5;

        return [
            'player_id' => $playerId,
            'username' => $player?->username,
            'avatar_url' => $player?->avatar_url,
            'hero_id' => $heroId,
            'role_id' => $roleId,
            'score' => round($score, 3),
            'recent_form' => $recentForm,
            'overall' => $overall,
            'hero_mastery' => $heroMastery,
            'role_mastery' => $roleMastery,
        ];
    }

    private function recentForm(int $playerId): array
    {
        $recent = MatchPlayer::where('player_id', $playerId)
            ->orderByDesc('id')
            ->limit(5)
            ->pluck('result');
        $total = $recent->count();
        $wins = $recent->filter(fn ($r) => $r === 'win')->count();

        return [
            'matches' => $total,
            'wins' => $wins,
            'rate' => $total > 0 ? $wins / $total : 0.5,
            'streak' => $recent->all(),
        ];
    }

    private function overallWinRate(int $playerId): array
    {
        $total = MatchPlayer::where('player_id', $playerId)->count();
        $wins = MatchPlayer::where('player_id', $playerId)->where('result', 'win')->count();

        return [
            'matches' => $total,
            'wins' => $wins,
            'rate' => $total > 0 ? $wins / $total : 0.5,
        ];
    }

    private function heroMastery(int $playerId, int $heroId): array
    {
        $total = MatchPlayer::where('player_id', $playerId)->where('hero_id', $heroId)->count();
        $wins = MatchPlayer::where('player_id', $playerId)->where('hero_id', $heroId)->where('result', 'win')->count();

        return [
            'matches' => $total,
            'wins' => $wins,
            'rate' => $total > 0 ? $wins / $total : 0.5,
        ];
    }

    private function roleMastery(int $playerId, int $roleId): array
    {
        $total = MatchPlayer::where('player_id', $playerId)->where('role_id', $roleId)->count();
        $wins = MatchPlayer::where('player_id', $playerId)->where('role_id', $roleId)->where('result', 'win')->count();

        return [
            'matches' => $total,
            'wins' => $wins,
            'rate' => $total > 0 ? $wins / $total : 0.5,
        ];
    }
}
