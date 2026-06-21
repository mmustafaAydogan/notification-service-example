<?php

namespace Tests\Feature\Models;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancellable_scope_only_returns_pending_notifications(): void
    {
        $pending = $this->createNotification(NotificationStatus::Pending);
        $this->createNotification(NotificationStatus::Processing);
        $this->createNotification(NotificationStatus::Sent);
        $this->createNotification(NotificationStatus::Failed);
        $this->createNotification(NotificationStatus::Cancelled);

        $cancellable = Notification::cancellable()->get();

        $this->assertCount(1, $cancellable);
        $this->assertTrue($cancellable->contains('id', $pending->id));
    }

    private function createNotification(NotificationStatus $status): Notification
    {
        return Notification::create([
            'idempotency_key' => bin2hex(random_bytes(8)),
            'channel'         => NotificationChannel::Sms->value,
            'priority'        => 1,
            'status'          => $status->value,
        ]);
    }
}
