<?php

namespace Tests\Feature\Jobs;

use App\Contracts\NotificationProvider;
use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Exceptions\PermanentDeliveryException;
use App\Exceptions\TransientDeliveryException;
use App\Jobs\ProcessNotificationJob;
use App\Models\Notification;
use App\Models\ScheduledDispatch;
use App\Models\SmsNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\RecordingProvider;
use Tests\TestCase;

class ProcessNotificationJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_marks_notification_as_sent_when_provider_succeeds(): void
    {
        $notification = $this->createPendingSms();
        $this->fakeProvider()->messageId = 'provider-msg-1';

        $this->runJob($notification->id);

        $notification->refresh();
        $this->assertSame(NotificationStatus::Sent, $notification->status);
        $this->assertSame('provider-msg-1', $notification->provider_message_id);
        $this->assertNotNull($notification->sent_at);
    }

    public function test_sends_channel_and_payload_built_from_notification(): void
    {
        $notification = $this->createPendingSms(recipient: '+905551112233', content: 'merhaba');
        $fake = $this->fakeProvider();

        $this->runJob($notification->id);

        $this->assertCount(1, $fake->calls);
        [$call] = $fake->calls;
        $this->assertSame(NotificationChannel::Sms, $call['channel']);
        $this->assertSame('+905551112233', $call['payload']['recipient']);
        $this->assertSame('merhaba', $call['payload']['content']);
        $this->assertSame($notification->id, $call['notificationId']);
    }

    public function test_marks_notification_as_failed_when_provider_returns_422(): void
    {
        $notification = $this->createPendingSms();
        $this->fakeProvider()->onSend = fn () => throw new PermanentDeliveryException('provider rejected payload');

        $this->runJob($notification->id);

        $notification->refresh();
        $this->assertSame(NotificationStatus::Failed, $notification->status);
        $this->assertSame('provider rejected payload', $notification->last_error);
    }

    public function test_reschedules_notification_when_provider_fails_transiently(): void
    {
        $notification = $this->createPendingSms();
        $this->fakeProvider()->onSend = fn () => throw new TransientDeliveryException('connection timed out');

        $this->runJob($notification->id);

        $notification->refresh();
        $this->assertSame(NotificationStatus::Pending, $notification->status);
        $this->assertSame(1, $notification->attempts);
        $this->assertSame('connection timed out', $notification->last_error);

        $dispatch = ScheduledDispatch::where('notification_id', $notification->id)->first();
        $this->assertNotNull($dispatch);
        $this->assertTrue($dispatch->dispatch_at->isFuture());
    }

    public function test_reschedules_notification_when_provider_connection_fails(): void
    {
        $notification = $this->createPendingSms();
        $this->fakeProvider()->onSend = fn () => throw new TransientDeliveryException('Provider connection error: cURL error 28');

        $this->runJob($notification->id);

        $notification->refresh();
        $this->assertSame(NotificationStatus::Pending, $notification->status);
        $this->assertSame(1, $notification->attempts);

        $dispatch = ScheduledDispatch::where('notification_id', $notification->id)->first();
        $this->assertNotNull($dispatch);
        $this->assertTrue($dispatch->dispatch_at->isFuture());
    }

    public function test_marks_failed_after_max_attempts_exhausted(): void
    {
        $notification = $this->createPendingSms();
        $notification->update(['attempts' => 4]); // next failure reaches MAX_ATTEMPTS (5)
        $this->fakeProvider()->onSend = fn () => throw new TransientDeliveryException('still failing');

        $this->runJob($notification->id);

        $notification->refresh();
        $this->assertSame(NotificationStatus::Failed, $notification->status);
        $this->assertSame(5, $notification->attempts);
        $this->assertDatabaseMissing('scheduled_dispatches', [
            'notification_id' => $notification->id,
        ]);
    }

    public function test_does_not_call_provider_when_notification_is_not_pending(): void
    {
        $notification = $this->createPendingSms();
        $notification->update(['status' => NotificationStatus::Cancelled]);
        $fake = $this->fakeProvider();

        $this->runJob($notification->id);

        $this->assertCount(0, $fake->calls);
        $this->assertSame(NotificationStatus::Cancelled, $notification->fresh()->status);
    }

    public function test_does_nothing_when_notification_is_missing(): void
    {
        $fake = $this->fakeProvider();

        $this->runJob((string) Str::uuid());

        $this->assertCount(0, $fake->calls);
    }

    private function fakeProvider(): RecordingProvider
    {
        $fake = new RecordingProvider();
        $this->app->instance(NotificationProvider::class, $fake);

        return $fake;
    }

    private function runJob(string $notificationId): void
    {
        $this->app->call([new ProcessNotificationJob($notificationId), 'handle']);
    }

    private function createPendingSms(string $recipient = '+905551112233', string $content = 'hi'): Notification
    {
        $notification = Notification::create([
            'idempotency_key' => bin2hex(random_bytes(8)),
            'channel'         => NotificationChannel::Sms->value,
            'priority'        => 1,
            'status'          => NotificationStatus::Pending->value,
        ]);

        SmsNotification::create([
            'notification_id' => $notification->id,
            'recipient'       => $recipient,
            'content'         => $content,
        ]);

        return $notification;
    }
}
