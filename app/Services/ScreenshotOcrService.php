<?php

namespace App\Services;

use Symfony\Component\Process\Process;
use Throwable;

class ScreenshotOcrService
{
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

        if (!$this->isTesseractAvailable()) {
            return [
                'success' => false,
                'message' => 'Tesseract is not installed on server',
                'text' => '',
                'parsed' => [],
            ];
        }

        $text = $this->runTesseract($absoluteImagePath);
        if ($text === null) {
            return [
                'success' => false,
                'message' => 'Failed to run OCR process',
                'text' => '',
                'parsed' => [],
            ];
        }

        $parsed = $this->extractMatchHints($text, $absoluteImagePath);

        return [
            'success' => true,
            'message' => 'OCR parsed successfully',
            'text' => $text,
            'parsed' => $parsed,
        ];
    }

    private function isTesseractAvailable(): bool
    {
        try {
            $process = new Process(['tesseract', '--version']);
            $process->run();

            return $process->isSuccessful();
        } catch (Throwable) {
            return false;
        }
    }

    private function runTesseract(string $absoluteImagePath): ?string
    {
        try {
            return $this->runTesseractPasses($absoluteImagePath);
        } catch (Throwable) {
            return null;
        }
    }

    private function runTesseractPasses(string $absoluteImagePath): ?string
    {
        $outputs = [];
        $passes = [
            ['tesseract', $absoluteImagePath, 'stdout', '-l', 'eng'],
            ['tesseract', $absoluteImagePath, 'stdout', '-l', 'eng', '--psm', '6'],
            ['tesseract', $absoluteImagePath, 'stdout', '-l', 'eng', '--psm', '11'],
            ['tesseract', $absoluteImagePath, 'stdout', '-l', 'eng', '--psm', '7'],
        ];

        foreach ($passes as $command) {
            $process = new Process($command);
            $process->setTimeout(30);
            $process->run();
            if ($process->isSuccessful()) {
                $output = trim($process->getOutput());
                if ($output !== '') {
                    $outputs[] = $output;
                }
            }
        }

        if ($outputs === []) {
            return null;
        }

        return trim(implode("\n\n", array_unique($outputs)));
    }

    private function extractMatchHints(string $rawText, ?string $absoluteImagePath = null): array
    {
        $normalized = strtoupper(preg_replace('/\s+/', ' ', $rawText) ?? '');
        $result = [];

        // Typical MLBB end-screen contains "VICTORY" when the left team wins.
        if (str_contains($normalized, 'VICTORY')) {
            $result['winner'] = 'team_a';
        }

        if (preg_match('/\b(\d{1,2})[:.](\d{2})\b/', $normalized, $duration)) {
            $result['duration'] = $duration[1].':'.$duration[2];
        }

        if (preg_match('/\b(\d{1,3})\s+(\d{1,3})\b/', $normalized, $score)) {
            $result['notes'] = 'OCR Score Hint: '.$score[1].' - '.$score[2];
        }

        $playersBySide = $absoluteImagePath ? $this->extractPlayersByImageSides($absoluteImagePath) : [];
        $result['players'] = $playersBySide !== [] ? $playersBySide : $this->extractPlayers($rawText);

        return $result;
    }

    private function extractPlayersByImageSides(string $absoluteImagePath): array
    {
        $sideTexts = $this->extractSideTexts($absoluteImagePath);
        if ($sideTexts === null) {
            return [];
        }

        $teamAPlayers = $this->extractPlayers($sideTexts['team_a'], 'team_a');
        $teamBPlayers = $this->extractPlayers($sideTexts['team_b'], 'team_b');

        return array_slice(array_merge($teamAPlayers, $teamBPlayers), 0, 10);
    }

    private function extractSideTexts(string $absoluteImagePath): ?array
    {
        try {
            $imageBytes = @file_get_contents($absoluteImagePath);
            if ($imageBytes === false) {
                return null;
            }

            $image = @imagecreatefromstring($imageBytes);
            if ($image === false) {
                return null;
            }

            $width = imagesx($image);
            $height = imagesy($image);
            if ($width < 200 || $height < 200) {
                return null;
            }

            $cropY = (int) round($height * 0.10);
            $cropHeight = (int) round($height * 0.68);
            $leftWidth = (int) round($width * 0.50);
            $rightX = (int) round($width * 0.50);
            $rightWidth = (int) round($width * 0.50);

            $leftText = $this->ocrCroppedArea($image, [
                'x' => 0,
                'y' => $cropY,
                'width' => $leftWidth,
                'height' => $cropHeight,
            ]);
            $rightText = $this->ocrCroppedArea($image, [
                'x' => $rightX,
                'y' => $cropY,
                'width' => $rightWidth,
                'height' => $cropHeight,
            ]);

            if ($leftText === null && $rightText === null) {
                return null;
            }

            return [
                'team_a' => $leftText ?? '',
                'team_b' => $rightText ?? '',
            ];
        } catch (Throwable) {
            return null;
        }
    }

    private function ocrCroppedArea(\GdImage $baseImage, array $rect): ?string
    {
        $cropped = imagecrop($baseImage, $rect);
        if ($cropped === false) {
            return null;
        }

        $tmpBase = tempnam(sys_get_temp_dir(), 'mlbb_ocr_');
        if ($tmpBase === false) {
            return null;
        }
        $tmpPath = $tmpBase.'.jpg';
        @unlink($tmpBase);

        $saved = imagejpeg($cropped, $tmpPath, 100);

        if (!$saved) {
            @unlink($tmpPath);
            return null;
        }

        $text = $this->runTesseractPasses($tmpPath);
        @unlink($tmpPath);

        return $text;
    }

    private function extractPlayers(string $rawText, ?string $forcedTeam = null): array
    {
        $lines = preg_split('/\R+/', $rawText) ?: [];
        $players = [];
        $seen = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strlen($line) < 8) {
                continue;
            }

            foreach ($this->extractPlayersFromLine($line) as $player) {
                $signature = strtolower($player['player_name']).'-'.$player['kills'].'-'.$player['deaths'].'-'.$player['assists'].'-'.($forcedTeam ?? '');
                if (isset($seen[$signature])) {
                    continue;
                }

                $seen[$signature] = true;
                $players[] = $player;
            }
        }

        if ($forcedTeam !== null) {
            $players = $this->appendNameOnlyFallbackPlayers($players, $lines, $seen);
        }

        if ($forcedTeam !== null) {
            foreach ($players as &$player) {
                $player['team'] = $forcedTeam;
            }
            unset($player);
            return array_slice($players, 0, 5);
        }

        $total = count($players);
        $teamASize = $total >= 10 ? 5 : (int) ceil($total / 2);
        foreach ($players as $index => &$player) {
            $player['team'] = $index < $teamASize ? 'team_a' : 'team_b';
        }
        unset($player);

        return array_slice($players, 0, 10);
    }

    private function extractPlayersFromLine(string $line): array
    {
        $clean = preg_replace('/[^\p{L}\p{N}\s._\/:-]/u', ' ', $line) ?? $line;
        $clean = preg_replace('/\s+/', ' ', $clean) ?? $clean;
        $results = [];

        $forwardPattern = '/(?<name>[A-Za-z][A-Za-z0-9_.]{3,})\s+(?<kills>\d{1,2})\s+(?<deaths>\d{1,2})\s+(?<assists>\d{1,2})(?:\s+\d{3,6})?(?:\s+(?<rating>\d{1,2}[.,]\d))?/u';
        if (preg_match_all($forwardPattern, $clean, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $entry = $this->makePlayerEntry($match, $clean);
                if ($entry !== []) {
                    $results[] = $entry;
                }
            }
        }

        $reversePattern = '/(?<kills>\d{1,2})\s+(?<deaths>\d{1,2})\s+(?<assists>\d{1,2})(?:\s+\d{3,6})?\s+(?<name>[A-Za-z][A-Za-z0-9_.]{3,})(?:\s+(?<rating>\d{1,2}[.,]\d))?/u';
        if (preg_match_all($reversePattern, $clean, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $entry = $this->makePlayerEntry($match, $clean);
                if ($entry !== []) {
                    $results[] = $entry;
                }
            }
        }

        return $results;
    }

    private function appendNameOnlyFallbackPlayers(array $players, array $lines, array $seen): array
    {
        $seenNames = [];
        foreach ($players as $player) {
            $seenNames[strtolower((string) ($player['player_name'] ?? ''))] = true;
        }

        foreach ($lines as $line) {
            $clean = preg_replace('/[^\p{L}\p{N}\s._\/:-]/u', ' ', trim($line)) ?? trim($line);
            $clean = preg_replace('/\s+/', ' ', $clean) ?? $clean;
            if ($clean === '') {
                continue;
            }

            if (!preg_match('/^(?:\d+\s+)?(?<name>[A-Za-z][A-Za-z0-9_.]{4,})\s+\d+/u', $clean, $match)) {
                continue;
            }

            $name = $this->normalizePlayerName((string) ($match['name'] ?? ''));
            if ($name === '' || $this->isInvalidNameToken($name)) {
                continue;
            }

            $nameKey = strtolower($name);
            if (isset($seenNames[$nameKey])) {
                continue;
            }

            $signature = $nameKey.'-0-0-0-';
            if (isset($seen[$signature])) {
                continue;
            }

            $seen[$signature] = true;
            $seenNames[$nameKey] = true;
            $players[] = [
                'player_name' => $name,
                'kills' => 0,
                'deaths' => 0,
                'assists' => 0,
                'rating' => null,
                'medal' => null,
            ];
        }

        return $players;
    }

    private function makePlayerEntry(array $match, string $line): array
    {
        $name = $this->normalizePlayerName((string) ($match['name'] ?? ''));
        $kills = (int) ($match['kills'] ?? 0);
        $deaths = (int) ($match['deaths'] ?? 0);
        $assists = (int) ($match['assists'] ?? 0);

        if ($kills > 40 || $deaths > 40 || $assists > 40) {
            return [];
        }

        $ratingRaw = (string) ($match['rating'] ?? '');
        $rating = null;
        if ($ratingRaw !== '') {
            $rating = (float) str_replace(',', '.', $ratingRaw);
            if ($rating < 0 || $rating > 20) {
                $rating = null;
            }
        }

        if ($rating === null) {
            $rating = $this->estimateRatingFromKda($kills, $deaths, $assists);
        }

        $medal = $this->extractMedalFromLine($line);
        if ($medal === null) {
            $medal = $this->estimateMedalFromRating($rating);
        }

        return [
            'player_name' => $name,
            'kills' => $kills,
            'deaths' => $deaths,
            'assists' => $assists,
            'rating' => $rating,
            'medal' => $medal,
        ];
    }

    private function normalizePlayerName(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9_.]/', '', $name) ?? $name;
        return trim($name, '._-');
    }

    private function isInvalidNameToken(string $name): bool
    {
        $upper = strtoupper($name);
        $invalid = ['VICTORY', 'DURASI', 'TEAM', 'MVP', 'BATTLE', 'DATA', 'LIKE', 'SEMUA'];
        return in_array($upper, $invalid, true);
    }

    private function extractMedalFromLine(string $line): ?string
    {
        $upper = strtoupper($line);
        if (str_contains($upper, 'MVP')) {
            return 'mvp_win';
        }
        if (str_contains($upper, 'GOLD')) {
            return 'gold';
        }
        if (str_contains($upper, 'SILVER')) {
            return 'silver';
        }
        if (str_contains($upper, 'BRONZE')) {
            return 'bronze';
        }

        return null;
    }

    private function estimateRatingFromKda(int $kills, int $deaths, int $assists): float
    {
        $score = 6 + ($kills * 0.35) + ($assists * 0.18) - ($deaths * 0.25);
        $score = max(3.0, min(15.0, $score));
        return round($score, 1);
    }

    private function estimateMedalFromRating(?float $rating): ?string
    {
        if ($rating === null) {
            return null;
        }
        if ($rating >= 10.8) {
            return 'mvp_win';
        }
        if ($rating >= 9.2) {
            return 'gold';
        }
        if ($rating >= 7.5) {
            return 'silver';
        }
        if ($rating >= 6.0) {
            return 'bronze';
        }
        return null;
    }
}

