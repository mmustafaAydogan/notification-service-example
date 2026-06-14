<?php

namespace Tests\Feature\Services;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\PriorityStatus;
use App\Exceptions\DuplicateNotificationException;
use App\Jobs\ProcessNotificationJob;
use App\Models\Notification;
use App\Models\SmsNotification;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class NotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private NotificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->service = app(NotificationService::class);
    }

    public function test_send_creates_pending_notification_with_channel_and_priority(): void
    {
        $result = $this->service->send(NotificationChannel::Sms, [
            'recipient' => '+905551112233',
            'content'   => 'hello',
            'priority'  => 'High',
        ]);

        $this->assertArrayHasKey('id', $result);
        $this->assertSame(NotificationStatus::Pending, $result['status']);

        $notification = Notification::find($result['id']);
        $this->assertNotNull($notification);
        $this->assertSame(NotificationChannel::Sms, $notification->channel);
        $this->assertSame(NotificationStatus::Pending, $notification->status);
        $this->assertSame(PriorityStatus::High->valueInt(), $notification->priority);
    }

    public function test_send_persists_channel_specific_detail_row(): void
    {
        $result = $this->service->send(NotificationChannel::Sms, [
            'recipient' => '+905551112233',
            'content'   => 'hi',
        ]);

        $sms = SmsNotification::where('notification_id', $result['id'])->first();
        $this->assertNotNull($sms);
        $this->assertSame('+905551112233', $sms->recipient);
        $this->assertSame('hi',            $sms->content);
    }

    public function test_send_dispatches_process_notification_job(): void
    {
        $result = $this->service->send(NotificationChannel::Sms, [
            'recipient' => '+905551112233',
            'content'   => 'hi',
        ]);

        Queue::assertPushed(
            ProcessNotificationJob::class,
            fn (ProcessNotificationJob $job) => $job->notificationId === $result['id'],
        );
    }

    public function test_send_propagates_priority_to_dispatched_job(): void
    {
        $this->service->send(NotificationChannel::Sms, [
            'recipient' => '+905551112233',
            'content'   => 'high-prio',
            'priority'  => 'High',
        ]);

        $this->service->send(NotificationChannel::Sms, [
            'recipient' => '+905557654321',
            'content'   => 'low-prio',
        ]);

        Queue::assertPushed(
            ProcessNotificationJob::class,
            fn (ProcessNotificationJob $job) => $job->priority === PriorityStatus::High->valueInt(),
        );
        Queue::assertPushed(
            ProcessNotificationJob::class,
            fn (ProcessNotificationJob $job) => $job->priority === PriorityStatus::Low->valueInt(),
        );
    }

    public function test_send_stores_idempotency_key_in_redis(): void
    {
        $result = $this->service->send(NotificationChannel::Sms, [
            'recipient' => '+905551112233',
            'content'   => 'hi',
        ]);

        $handler = app(\App\Services\Notification\Channels\ChannelHandlerRegistry::class)
            ->handlerFor(NotificationChannel::Sms);

        $key = $handler->idempotencyHash([
            'recipient' => '+905551112233',
            'content'   => 'hi',
        ]);

        $this->assertSame($result['id'], Redis::get("idempotency:{$key}"));
    }

    public function test_send_throws_duplicate_exception_on_idempotency_hit(): void
    {
        $payload = ['recipient' => '+905551112233', 'content' => 'hi'];

        $first = $this->service->send(NotificationChannel::Sms, $payload);

        try {
            $this->service->send(NotificationChannel::Sms, $payload);
            $this->fail('Expected DuplicateNotificationException was not thrown.');
        } catch (DuplicateNotificationException $e) {
            $this->assertSame($first['id'], $e->existingNotificationId);
        }

        $this->assertSame(1, Notification::count());
    }

    public function test_send_defaults_to_low_priority_when_omitted(): void
    {
        $result = $this->service->send(NotificationChannel::Sms, [
            'recipient' => '+905551112233',
            'content'   => 'hi',
        ]);

        $this->assertSame(PriorityStatus::Low->valueInt(), Notification::find($result['id'])->priority);
    }
}
