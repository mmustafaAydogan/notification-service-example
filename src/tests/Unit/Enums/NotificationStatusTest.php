<?php

namespace Tests\Unit\Enums;

use App\Enums\NotificationStatus;
use PHPUnit\Framework\TestCase;

class NotificationStatusTest extends TestCase
{
    public function test_only_pending_is_cancellable(): void
    {
        $this->assertTrue(NotificationStatus::Pending->isCancellable());

        $this->assertFalse(NotificationStatus::Processing->isCancellable());
        $this->assertFalse(NotificationStatus::Sent->isCancellable());
        $this->assertFalse(NotificationStatus::Failed->isCancellable());
        $this->assertFalse(NotificationStatus::Cancelled->isCancellable());
    }
}
