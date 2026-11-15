<?php

namespace App\Services;

use App\Models\DeviceToken;
use App\Models\DriverAlert;
use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * In-app driver alerts + FCM push (HTTP v1 API).
 *
 * Requires FIREBASE_CREDENTIALS pointing to the Firebase service-account JSON
 * (default: storage/app/firebase-service-account.json). When the file is
 * missing the in-app alert is still stored and the push is skipped.
 */
class DriverNotificationService
{
    private ?string $accessToken = null;

    public function send(int $driverId, string $title, string $body): void
    {
        DriverAlert::create([
            'driver_id' => $driverId,
            'title' => $title,
            'body' => $body,
        ]);

        $this->push($driverId, $title, $body);
    }

    private function push(int $driverId, string $title, string $body): void
    {
        $tokens = DeviceToken::where('user_id', $driverId)->pluck('token');
        if ($tokens->isEmpty()) {
            return;
        }

        try {
            $accessToken = $this->accessToken();
        } catch (\Throwable $e) {
            Log::warning('FCM credentials unavailable', ['error' => $e->getMessage()]);
            return;
        }

        $projectId = config('services.firebase.project_id', 'tr3slog');
        $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

        foreach ($tokens as $token) {
            $res = Http::withToken($accessToken)->post($url, [
                'message' => [
                    'token' => $token,
                    'notification' => ['title' => $title, 'body' => $body],
                    'data' => ['title' => $title, 'body' => $body],
                    'android' => ['priority' => 'HIGH'],
                ],
            ]);

            if ($res->status() === 404) {
                // Token is gone from FCM — clean it up.
                DeviceToken::where('token', $token)->delete();
            }
        }
    }

    private function accessToken(): string
    {
        if ($this->accessToken) {
            return $this->accessToken;
        }

        $path = config('services.firebase.credentials')
            ?: storage_path('app/firebase-service-account.json');
        $credentials = new ServiceAccountCredentials(
            'https://www.googleapis.com/auth/firebase.messaging',
            json_decode(file_get_contents($path), true)
        );

        $token = $credentials->fetchAuthToken();

        return $this->accessToken = $token['access_token'];
    }
}
