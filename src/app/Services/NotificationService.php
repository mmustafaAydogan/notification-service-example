<?php

namespace App\Services;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\PriorityStatus;
use App\Exceptions\DuplicateNotificationException;
use App\Jobs\ProcessNotificationJob;
use App\Models\Notification;
use App\Models\ScheduledDispatch;
use App\Services\Notification\Channels\ChannelHandlerRegistry;
use Illuminate\Database\UniqueConstraintViolationException;
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

        $scheduledAt = $data['scheduled_at'] ?? null;

        try {
            $notification = DB::transaction(function () use ($channel, $data, $idempotencyKey, $handler, $scheduledAt) {
                $notification = Notification::create([
                    'idempotency_key' => $idempotencyKey,
                    'channel'         => $channel,
                    'priority'        => PriorityStatus::tryFrom($data['priority'] ?? '')?->valueInt() ?? PriorityStatus::Low->valueInt(),
                    'status'          => NotificationStatus::Pending,
                    'batch_id'        => $data['batch_id'] ?? null,
                    'scheduled_at'    => $scheduledAt,
                ]);

                $handler->persistDetail($notification->id, $data);

                if ($scheduledAt !== null) {
                    ScheduledDispatch::create([
                        'notification_id' => $notification->id,
                        'dispatch_at'     => $scheduledAt,
                    ]);
                }

                return $notification;
            });
        } catch (UniqueConstraintViolationException $e) {
            throw $this->resolveDuplicate($idempotencyKey, $e);
        }

        $this->storeIdempotencyKey($idempotencyKey, $notification->id);

        if ($scheduledAt === null) {
            $this->dispatch($notification);
        }

        return [
            'id'         => $notification->id,
            'status'     => $notification->status,
            'created_at' => $notification->created_at->format('Y-m-d H:i:s'),
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

    private function resolveDuplicate(string $key, UniqueConstraintViolationException $e): \Throwable
    {
        $existing = Notification::where('idempotency_key', $key)->first();

        if ($existing === null) {
            return $e;
        }

        $this->storeIdempotencyKey($key, $existing->id);

        return new DuplicateNotificationException($existing->id);
    }

    private function storeIdempotencyKey(string $key, string $notificationId): void
    {
        Redis::setex("idempotency:{$key}", 86400, $notificationId);
    }

    private function dispatch(Notification $notification): void
    {
        dispatch(new ProcessNotificationJob(
            notificationId: $notification->id,
            priority:       $notification->priority,
        ));
    }
}
