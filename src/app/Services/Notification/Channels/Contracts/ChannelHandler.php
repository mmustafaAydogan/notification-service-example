<?php

namespace App\Services\Notification\Channels\Contracts;

use App\Enums\NotificationChannel;

interface ChannelHandler
{
    public function channel(): NotificationChannel;

    public function validationRules(): array;

    public function idempotencyHash(array $data): string;

    public function persistDetail(string $notificationId, array $data): void;

    /**
     * @param array<int, array{notification_id: string, data: array}> $items
     */
    public function persistDetailsBatch(array $items): void;

    public function payloadFromNotification(\App\Models\Notification $notification): array;
}
