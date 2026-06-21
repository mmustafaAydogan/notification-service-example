<?php

namespace Tests\Feature\Api;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\PriorityStatus;
use App\Http\Middleware\LogRequests;
use App\Jobs\ProcessNotificationJob;
use App\Models\EmailNotification;
use App\Models\Notification;
use App\Models\PushNotification;
use App\Models\SmsNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(LogRequests::class);
        Queue::fake();
    }

    public function test_send_sms_returns_202_and_persists_pending_notification(): void
    {
        $response = $this->postJson('/api/notifications/sms', [
            'recipient' => '+905551112233',
            'content'   => 'merhaba',
        ]);

        $response->assertStatus(202)
            ->assertJsonStructure(['id', 'status', 'created_at']);

        $id = $response->json('id');
        $this->assertDatabaseHas('notifications', [
            'id'      => $id,
            'channel' => NotificationChannel::Sms->value,
            'status'  => NotificationStatus::Pending->value,
        ]);
        $this->assertDatabaseHas('sms_notifications', [
            'notification_id' => $id,
            'recipient'       => '+905551112233',
            'content'         => 'merhaba',
        ]);

        Queue::assertPushed(ProcessNotificationJob::class);
    }

    public function test_send_sms_returns_409_on_duplicate_idempotency_key(): void
    {
        $payload = ['recipient' => '+905551112233', 'content' => 'hi'];

        $first = $this->postJson('/api/notifications/sms', $payload);
        $first->assertStatus(202);

        $duplicate = $this->postJson('/api/notifications/sms', $payload);
        $duplicate->assertStatus(409)
            ->assertJson([
                'existing_notification_id' => $first->json('id'),
            ]);
    }

    public function test_send_sms_returns_422_for_invalid_recipient(): void
    {
        $response = $this->postJson('/api/notifications/sms', [
            'recipient' => 'not-a-phone',
            'content'   => 'hi',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('recipient');
    }

    public function test_send_email_persists_email_detail(): void
    {
        $response = $this->postJson('/api/notifications/email', [
            'recipient' => 'user@example.com',
            'subject'   => 'Hello',
            'body'      => 'Welcome',
        ]);

        $response->assertStatus(202);
        $this->assertDatabaseHas('email_notifications', [
            'notification_id' => $response->json('id'),
            'recipient'       => 'user@example.com',
            'subject'         => 'Hello',
        ]);
    }

    public function test_send_push_persists_push_detail(): void
    {
        $response = $this->postJson('/api/notifications/push', [
            'device_token' => 'token-1234567890',
            'title'        => 'Hey',
            'body'         => 'You got mail',
        ]);

        $response->assertStatus(202);
        $this->assertDatabaseHas('push_notifications', [
            'notification_id' => $response->json('id'),
            'device_token'    => 'token-1234567890',
            'title'           => 'Hey',
        ]);
    }

    public function test_index_returns_paginated_list(): void
    {
        $this->seedNotifications();

        $response = $this->getJson('/api/notifications?per_page=2');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [['id', 'channel', 'status']],
                'meta' => ['current_page', 'per_page', 'total'],
            ])
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3);
    }

    public function test_index_filters_by_status(): void
    {
        $this->seedNotifications();

        $response = $this->getJson('/api/notifications?status=sent');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', NotificationStatus::Sent->value);
    }

    public function test_index_filters_by_channel(): void
    {
        $this->seedNotifications();

        $response = $this->getJson('/api/notifications?channel=email');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.channel', NotificationChannel::Email->value);
    }

    public function test_show_returns_notification_with_channel_detail(): void
    {
        $sms = $this->createSmsNotification();

        $response = $this->getJson("/api/notifications/{$sms->id}");

        $response->assertStatus(200)
            ->assertJsonPath('id', $sms->id)
            ->assertJsonPath('channel', NotificationChannel::Sms->value)
            ->assertJsonPath('detail.recipient', '+905550000001')
            ->assertJsonPath('detail.content',   'sample');
    }

    public function test_show_returns_404_when_not_found(): void
    {
        $response = $this->getJson('/api/notifications/'.Str::uuid());

        $response->assertStatus(404)->assertJson(['message' => 'Notification not found.']);
    }

    public function test_cancel_marks_pending_notification_as_cancelled(): void
    {
        $sms = $this->createSmsNotification();

        $response = $this->postJson("/api/notifications/cancel/{$sms->id}");

        $response->assertStatus(200)
            ->assertExactJson([
                'id'     => $sms->id,
                'status' => NotificationStatus::Cancelled->value,
            ]);

        $this->assertSame(
            NotificationStatus::Cancelled->value,
            Notification::find($sms->id)->status->value,
        );
    }

    public function test_cancel_returns_409_for_terminal_status(): void
    {
        $notification = Notification::create([
            'idempotency_key' => 'sent-key-123',
            'channel'         => NotificationChannel::Sms->value,
            'priority'        => 1,
            'status'          => NotificationStatus::Sent->value,
        ]);

        $response = $this->postJson("/api/notifications/cancel/{$notification->id}");

        $response->assertStatus(409)
            ->assertJsonStructure(['message']);
    }

    public function test_cancel_returns_404_when_not_found(): void
    {
        $response = $this->postJson('/api/notifications/cancel/'.Str::uuid());

        $response->assertStatus(404);
    }

    public function test_cancel_batch_cancels_all_cancellable_in_batch(): void
    {
        $batchId = (string) Str::uuid();

        $pending1 = $this->createSmsNotification(batchId: $batchId);
        $pending2 = $this->createSmsNotification(batchId: $batchId, recipient: '+905550000002');
        $sent     = Notification::create([
            'idempotency_key' => 'sent-key-xyz',
            'batch_id'        => $batchId,
            'channel'         => NotificationChannel::Sms->value,
            'priority'        => 1,
            'status'          => NotificationStatus::Sent->value,
        ]);

        $response = $this->postJson("/api/notifications/cancel/batch/{$batchId}");

        $response->assertStatus(200)
            ->assertJsonCount(2, 'cancelled');

        $this->assertSame(NotificationStatus::Cancelled->value, Notification::find($pending1->id)->status->value);
        $this->assertSame(NotificationStatus::Cancelled->value, Notification::find($pending2->id)->status->value);
        $this->assertSame(NotificationStatus::Sent->value,      Notification::find($sent->id)->status->value);
    }

    public function test_bulk_propagates_priority_per_item_to_dispatched_jobs(): void
    {
        $this->postJson('/api/notifications/bulk', [
            'notifications' => [
                ['channel' => 'sms', 'recipient' => '+905550000010', 'content' => 'a', 'priority' => 'High'],
                ['channel' => 'sms', 'recipient' => '+905550000011', 'content' => 'b', 'priority' => 'Medium'],
                ['channel' => 'sms', 'recipient' => '+905550000012', 'content' => 'c'],
            ],
        ])->assertStatus(202)->assertJsonPath('accepted', 3);

        Queue::assertPushed(
            ProcessNotificationJob::class,
            fn (ProcessNotificationJob $job) => $job->priority === PriorityStatus::High->valueInt(),
        );
        Queue::assertPushed(
            ProcessNotificationJob::class,
            fn (ProcessNotificationJob $job) => $job->priority === PriorityStatus::Medium->valueInt(),
        );
        Queue::assertPushed(
            ProcessNotificationJob::class,
            fn (ProcessNotificationJob $job) => $job->priority === PriorityStatus::Low->valueInt(),
        );
    }

    public function test_bulk_returns_accepted_count_and_errors(): void
    {
        $response = $this->postJson('/api/notifications/bulk', [
            'notifications' => [
                ['channel' => 'sms',   'recipient' => '+905551110001', 'content' => 'hi-1'],
                ['channel' => 'email', 'recipient' => 'u@example.com', 'subject' => 'S', 'body' => 'B'],
                ['channel' => 'sms',   'recipient' => 'not-a-phone',   'content' => 'invalid'],
            ],
        ]);

        $response->assertStatus(202)
            ->assertJsonStructure(['batch_id', 'accepted', 'rejected', 'errors'])
            ->assertJsonPath('accepted', 2)
            ->assertJsonPath('rejected', 1)
            ->assertJsonPath('errors.0.index', 2);
    }

    private function seedNotifications(): void
    {
        $this->createSmsNotification();

        Notification::create([
            'idempotency_key' => 'em-key',
            'channel'         => NotificationChannel::Email->value,
            'priority'        => 5,
            'status'          => NotificationStatus::Pending->value,
        ]);

        $sent = Notification::create([
            'idempotency_key' => 'pu-key',
            'channel'         => NotificationChannel::Push->value,
            'priority'        => 10,
            'status'          => NotificationStatus::Sent->value,
        ]);
        $sent->update(['sent_at' => now(), 'provider_message_id' => 'msg-1']);
    }

    private function createSmsNotification(?string $batchId = null, string $recipient = '+905550000001'): Notification
    {
        $notification = Notification::create([
            'idempotency_key' => bin2hex(random_bytes(8)),
            'batch_id'        => $batchId,
            'channel'         => NotificationChannel::Sms->value,
            'priority'        => 1,
            'status'          => NotificationStatus::Pending->value,
        ]);

        SmsNotification::create([
            'notification_id' => $notification->id,
            'recipient'       => $recipient,
            'content'         => 'sample',
        ]);

        return $notification;
    }
}
