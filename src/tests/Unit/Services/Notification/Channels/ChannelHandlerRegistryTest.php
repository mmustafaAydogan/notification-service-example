<?php

namespace Tests\Unit\Services\Notification\Channels;

use App\Enums\NotificationChannel;
use App\Services\Notification\Channels\ChannelHandlerRegistry;
use App\Services\Notification\Channels\EmailChannelHandler;
use App\Services\Notification\Channels\PushChannelHandler;
use App\Services\Notification\Channels\SmsChannelHandler;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ChannelHandlerRegistryTest extends TestCase
{
    private ChannelHandlerRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = new ChannelHandlerRegistry([
            new SmsChannelHandler(),
            new EmailChannelHandler(),
            new PushChannelHandler(),
        ]);
    }

    public function test_handler_for_returns_matching_handler(): void
    {
        $this->assertInstanceOf(SmsChannelHandler::class,   $this->registry->handlerFor(NotificationChannel::Sms));
        $this->assertInstanceOf(EmailChannelHandler::class, $this->registry->handlerFor(NotificationChannel::Email));
        $this->assertInstanceOf(PushChannelHandler::class,  $this->registry->handlerFor(NotificationChannel::Push));
    }

    public function test_handler_for_throws_when_no_handler_registered(): void
    {
        $empty = new ChannelHandlerRegistry([]);

        $this->expectException(InvalidArgumentException::class);
        $empty->handlerFor(NotificationChannel::Sms);
    }

    public function test_rules_for_merges_channel_rules_with_common_rules(): void
    {
        $rules = $this->registry->rulesFor(NotificationChannel::Sms);

        $this->assertArrayHasKey('recipient',    $rules);
        $this->assertArrayHasKey('content',      $rules);
        $this->assertArrayHasKey('priority',     $rules);
        $this->assertArrayHasKey('batch_id',     $rules);
        $this->assertArrayHasKey('scheduled_at', $rules);
    }
}
