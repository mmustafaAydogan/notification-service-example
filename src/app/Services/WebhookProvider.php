<?php

namespace App\Services;

use App\Contracts\NotificationProvider;
use App\Contracts\ProviderResponse;
use App\Enums\NotificationChannel;
use Illuminate\Support\Facades\Http;

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

    public function send(NotificationChannel $channel, array $payload): ProviderResponse
    {
        $body = array_merge($payload, ['channel' => $channel->value]);

        $response = Http::timeout(5)->post($this->webhookUrl, $body);

        if ($response->status() === 422) {
            throw new \RuntimeException('Provider validation error: ' . $response->body(), 422);
        }

        if (!$response->successful()) {
            throw new \RuntimeException('Provider error: HTTP ' . $response->status(), $response->status());
        }

        return new ProviderResponse(
            messageId: $response->json('messageId') ?? '',
        );
    }
}
