<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CloudinaryUploader
{
    public function isConfigured(): bool
    {
        return (bool) config('services.cloudinary.cloud_name')
            && (bool) config('services.cloudinary.api_key')
            && (bool) config('services.cloudinary.api_secret');
    }

    /**
     * @return array{file_path: string, public_id: string|null}
     */
    public function uploadScreenshot(UploadedFile $file): array
    {
        $cloudName = (string) config('services.cloudinary.cloud_name');
        $apiKey = (string) config('services.cloudinary.api_key');
        $apiSecret = (string) config('services.cloudinary.api_secret');
        $folder = (string) config('services.cloudinary.folder', 'mlbb-stats/screenshots');

        if (!$cloudName || !$apiKey || !$apiSecret) {
            throw new RuntimeException('Cloudinary is not configured.');
        }

        $timestamp = time();
        $signature = $this->sign([
            'folder' => $folder,
            'timestamp' => (string) $timestamp,
        ], $apiSecret);

        $url = sprintf('https://api.cloudinary.com/v1_1/%s/image/upload', $cloudName);

        $response = Http::asMultipart()->post($url, [
            [
                'name' => 'file',
                'contents' => fopen($file->getRealPath(), 'r'),
                'filename' => $file->getClientOriginalName() ?: 'screenshot.jpg',
            ],
            ['name' => 'api_key', 'contents' => $apiKey],
            ['name' => 'timestamp', 'contents' => (string) $timestamp],
            ['name' => 'folder', 'contents' => $folder],
            ['name' => 'signature', 'contents' => $signature],
        ]);

        if (!$response->successful()) {
            throw new RuntimeException('Failed uploading screenshot to Cloudinary: '.$response->body());
        }

        /** @var array{secure_url?: string, public_id?: string} $payload */
        $payload = $response->json();
        $secureUrl = $payload['secure_url'] ?? null;

        if (!$secureUrl) {
            throw new RuntimeException('Cloudinary response missing secure_url.');
        }

        return [
            'file_path' => $secureUrl,
            'public_id' => $payload['public_id'] ?? null,
        ];
    }

    /**
     * @param array<string, string> $params
     */
    private function sign(array $params, string $apiSecret): string
    {
        ksort($params);
        $parts = [];
        foreach ($params as $key => $value) {
            $parts[] = $key.'='.$value;
        }
        $query = implode('&', $parts);

        return sha1($query.$apiSecret);
    }
}

