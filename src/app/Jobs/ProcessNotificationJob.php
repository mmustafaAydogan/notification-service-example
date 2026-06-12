<?php

namespace App\Jobs;

use App\Contracts\NotificationProvider;
use App\Enums\NotificationStatus;
use App\Models\Notification;
use App\Services\Notification\Channels\ChannelHandlerRegistry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\RateLimiter;

class ProcessNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries   = 1;
    public int $timeout = 30;

    private const MAX_ATTEMPTS                  = 5;
    private const RETRY_DELAY_MINUTES           = 15;
    private const RATE_LIMIT_PER_SECOND         = 100;
    private const RATE_LIMIT_REDISPATCH_SECONDS = 2;

    public function __construct(
        public readonly string $notificationId,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(NotificationProvider $provider, ChannelHandlerRegistry $registry): void
    {
        $notification = Notification::with([
            'smsNotification', 'emailNotification', 'pushNotification',
        ])->find($this->notificationId);

        if (!$notification) {
            return;
        }

        if ($notification->status !== NotificationStatus::Pending) {
            return;
        }

        $rateLimitKey = 'notifications-' . $notification->channel->value;

        if (RateLimiter::tooManyAttempts($rateLimitKey, self::RATE_LIMIT_PER_SECOND)) {
            self::dispatch($this->notificationId)
                ->delay(now()->addSeconds(self::RATE_LIMIT_REDISPATCH_SECONDS));
            return;
        }
        RateLimiter::hit($rateLimitKey, 1);

        if (!$notification->markAsProcessing()) {
            return;
        }

        $payload = $registry->handlerFor($notification->channel)
            ->payloadFromNotification($notification);

        try {
            $response = $provider->send($notification->channel, $payload, $notification->id);
            $notification->markAsSent($response->messageId);
        } catch (\RuntimeException $e) {
            if ($e->getCode() === 422) {
                $notification->markAsFailed($e->getMessage());
                return;
            }

            $notification->recordError($e->getMessage());

            if ($notification->attempts >= self::MAX_ATTEMPTS) {
                $notification->markAsFailed($e->getMessage());
                return;
            }

            $notification->update([
                'status'       => NotificationStatus::Pending,
                'scheduled_at' => now()->addMinutes(self::RETRY_DELAY_MINUTES),
            ]);
        }
    }
}
