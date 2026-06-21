<?php

namespace Tests\Support;

use App\Contracts\NotificationProvider;
use App\Contracts\ProviderResponse;
use App\Enums\NotificationChannel;

/**
 * In-memory NotificationProvider test double: records every send() call and lets
 * a test either return a fixed messageId or throw, without touching the network.
 */
class RecordingProvider implements NotificationProvider
{
    /** @var array<int, array{channel: NotificationChannel, payload: array, notificationId: ?string}> */
    public array $calls = [];

    public string $messageId = 'fake-message-id';

    /** @var (\Closure(NotificationChannel, array, ?string): ProviderResponse)|null */
    public ?\Closure $onSend = null;

    public function send(NotificationChannel $channel, array $payload, ?string $notificationId = null): ProviderResponse
    {
        $this->calls[] = compact('channel', 'payload', 'notificationId');

        if ($this->onSend !== null) {
            return ($this->onSend)($channel, $payload, $notificationId);
        }

        return new ProviderResponse(messageId: $this->messageId);
    }
}
