<?php

namespace Tests\Feature\Console;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\PriorityStatus;
use App\Jobs\ProcessNotificationJob;
use App\Models\Notification;
use App\Models\ScheduledDispatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DispatchDueNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_dispatches_due_row_and_deletes_it(): void
    {
        $notification = $this->makeNotification();
        ScheduledDispatch::create([
            'notification_id' => $notification->id,
            'dispatch_at'     => now()->subMinute(),
        ]);

        $this->artisan('notifications:dispatch-due')->assertSuccessful();

        Queue::assertPushed(
            ProcessNotificationJob::class,
            fn (ProcessNotificationJob $job) => $job->notificationId === $notification->id,
        );

        $this->assertDatabaseMissing('scheduled_dispatches', [
            'notification_id' => $notification->id,
        ]);
    }

    public function test_skips_future_rows_and_keeps_them(): void
    {
        $notification = $this->makeNotification();
        ScheduledDispatch::create([
            'notification_id' => $notification->id,
            'dispatch_at'     => now()->addHour(),
        ]);

        $this->artisan('notifications:dispatch-due')->assertSuccessful();

        Queue::assertNotPushed(ProcessNotificationJob::class);

        $this->assertDatabaseHas('scheduled_dispatches', [
            'notification_id' => $notification->id,
        ]);
    }

    public function test_dispatched_job_carries_notification_priority(): void
    {
        $notification = $this->makeNotification(PriorityStatus::High->valueInt());
        ScheduledDispatch::create([
            'notification_id' => $notification->id,
            'dispatch_at'     => now()->subMinute(),
        ]);

        $this->artisan('notifications:dispatch-due')->assertSuccessful();

        Queue::assertPushed(
            ProcessNotificationJob::class,
            fn (ProcessNotificationJob $job) => $job->priority === PriorityStatus::High->valueInt(),
        );
    }

    private function makeNotification(int $priority = 1): Notification
    {
        return Notification::create([
            'idempotency_key' => bin2hex(random_bytes(8)),
            'channel'         => NotificationChannel::Sms->value,
            'priority'        => $priority,
            'status'          => NotificationStatus::Pending->value,
        ]);
    }
}
