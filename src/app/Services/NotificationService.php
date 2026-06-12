<?php

namespace App\Services;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\PriorityStatus;
use App\Exceptions\DuplicateNotificationException;
use App\Jobs\ProcessNotificationJob;
use App\Models\Notification;
use App\Services\Notification\Channels\ChannelHandlerRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class NotificationService
{
    public function __construct(
        private ChannelHandlerRegistry $registry,
    ) {}

    public function send(NotificationChannel $channel, array $data): array
    {
        $handler = $this->registry->handlerFor($channel);
        $idempotencyKey = $handler->idempotencyHash($data);

        $duplicate = $this->checkIdempotency($idempotencyKey);
        if ($duplicate) {
            throw new DuplicateNotificationException($duplicate->id);
        }

        $notification = DB::transaction(function () use ($channel, $data, $idempotencyKey, $handler) {
            $notification = Notification::create([
                'idempotency_key' => $idempotencyKey,
                'channel'         => $channel,
                'priority'        => PriorityStatus::tryFrom($data['priority'] ?? '')?->valueInt() ?? PriorityStatus::Low->valueInt(),
                'status'          => NotificationStatus::Pending,
                'batch_id'        => $data['batch_id'] ?? null,
                'scheduled_at'    => $data['scheduled_at'] ?? null,
            ]);

            $handler->persistDetail($notification->id, $data);

            return $notification;
        });

        $this->storeIdempotencyKey($idempotencyKey, $notification->id);
        $this->dispatch($notification);

        return [
            'id'         => $notification->id,
            'status'     => $notification->status,
            'created_at' => $notification->created_at,
        ];
    }


    private function checkIdempotency(string $key): ?Notification
    {
        $notificationId = Redis::get("idempotency:{$key}");

        if (!$notificationId) {
            return null;
        }

        return Notification::find($notificationId);
    }

    private function storeIdempotencyKey(string $key, string $notificationId): void
    {
        Redis::setex("idempotency:{$key}", 86400, $notificationId);
    }

    private function dispatch(Notification $notification): void
    {
        dispatch(new ProcessNotificationJob(
            notificationId: $notification->id,
        ));
    }
}
