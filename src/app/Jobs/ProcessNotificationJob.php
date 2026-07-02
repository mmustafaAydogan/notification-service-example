<?php

namespace App\Jobs;

use App\Contracts\NotificationProvider;
use App\Enums\NotificationStatus;
use App\Exceptions\PermanentDeliveryException;
use App\Exceptions\TransientDeliveryException;
use App\Models\Notification;
use App\Models\ScheduledDispatch;
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

    public int $priority;

    public function __construct(
        public readonly string $notificationId,
        int $priority = 0,
    ) {
        $this->priority = $priority;
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
            self::dispatch($this->notificationId, $this->priority)
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
        } catch (PermanentDeliveryException $e) {
            $notification->markAsFailed($e->getMessage());
            return;
        } catch (TransientDeliveryException $e) {
            $this->handleTransientFailure($notification, $e->getMessage());
            return;
        }

        $notification->markAsSent($response->messageId);
    }

    private function handleTransientFailure(Notification $notification, string $reason): void
    {
        $notification->recordError($reason);

        if ($notification->attempts >= self::MAX_ATTEMPTS) {
            $notification->markAsFailed($reason);
            return;
        }

        $notification->update(['status' => NotificationStatus::Pending]);

        ScheduledDispatch::create([
            'notification_id' => $notification->id,
            'dispatch_at'     => now()->addMinutes(self::RETRY_DELAY_MINUTES),
        ]);
    }
}
