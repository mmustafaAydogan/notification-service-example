<?php

namespace App\Services;

use App\Contracts\NotificationProvider;
use App\Contracts\ProviderResponse;
use App\Enums\NotificationChannel;
use App\Exceptions\PermanentDeliveryException;
use App\Exceptions\TransientDeliveryException;
use App\Models\OutgoingRequestLog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Facades\Http;
use Throwable;

class WebhookProvider implements NotificationProvider
{
    private string $webhookUrl;

    public function __construct()
    {
        $url = config('services.webhook_site.url');

        if (!$url) {
            throw new \RuntimeException('services.webhook_site.url config is missing');
        }

        $this->webhookUrl = rtrim($url, '/');
    }

    public function send(NotificationChannel $channel, array $payload, ?string $notificationId = null): ProviderResponse
    {
        $body = array_merge($payload, ['channel' => $channel->value]);

        $startedAt = microtime(true);
        $response = null;
        $error = null;

        try {
            try {
                $response = Http::timeout(5)->post($this->webhookUrl, $body);
            } catch (ConnectionException $e) {
                throw new TransientDeliveryException('Provider connection error: ' . $e->getMessage(), previous: $e);
            }

            if ($response->status() === 422) {
                throw new PermanentDeliveryException('Provider rejected payload: ' . $response->body());
            }

            if (!$response->successful()) {
                throw new TransientDeliveryException('Provider error: HTTP ' . $response->status());
            }

            return new ProviderResponse(
                messageId: $response->json('messageId') ?? '',
            );
        } catch (Throwable $e) {
            $error = $e->getMessage();
            throw $e;
        } finally {
            $this->log($channel, $notificationId, $body, $response, $error, $startedAt);
        }
    }

    private function log(
        NotificationChannel $channel,
        ?string $notificationId,
        array $requestBody,
        ?HttpResponse $response,
        ?string $error,
        float $startedAt,
    ): void {
        try {
            OutgoingRequestLog::create([
                'notification_id' => $notificationId,
                'channel' => $channel->value,
                'method' => 'POST',
                'url' => $this->webhookUrl,
                'request_body' => $requestBody,
                'status_code' => $response?->status(),
                'response_body' => $response ? $this->capture($response->body()) : ['empty' => true],
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'error' => $error,
                'logged_at' => now(),
            ]);
        } catch (Throwable $e) {
            logger()->warning('outgoing_request_log_write_failed', [
                'notification_id' => $notificationId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function capture(string $body): array
    {
        if ($body === '') {
            return ['empty' => true];
        }

        $decoded = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return ['json' => $decoded];
        }

        return ['raw' => $body];
    }
}
