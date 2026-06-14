<?php

namespace Tests\Unit\Services\Notification\Channels;

use App\Enums\NotificationChannel;
use App\Services\Notification\Channels\SmsChannelHandler;
use PHPUnit\Framework\TestCase;

class SmsChannelHandlerTest extends TestCase
{
    private SmsChannelHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = new SmsChannelHandler();
    }

    public function test_channel_is_sms(): void
    {
        $this->assertSame(NotificationChannel::Sms, $this->handler->channel());
    }

    public function test_validation_rules_require_recipient_and_content(): void
    {
        $rules = $this->handler->validationRules();

        $this->assertArrayHasKey('recipient', $rules);
        $this->assertArrayHasKey('content',   $rules);
        $this->assertContains('required', $rules['recipient']);
        $this->assertContains('required', $rules['content']);
    }

    public function test_idempotency_hash_is_deterministic(): void
    {
        $payload = ['recipient' => '+905551234567', 'content' => 'merhaba'];

        $this->assertSame(
            $this->handler->idempotencyHash($payload),
            $this->handler->idempotencyHash($payload),
        );
    }

    public function test_idempotency_hash_changes_with_payload(): void
    {
        $base = ['recipient' => '+905551234567', 'content' => 'merhaba'];
        $hash = $this->handler->idempotencyHash($base);

        $this->assertNotSame($hash, $this->handler->idempotencyHash([...$base, 'content' => 'baska']));
        $this->assertNotSame($hash, $this->handler->idempotencyHash([...$base, 'recipient' => '+905557654321']));
    }
}
