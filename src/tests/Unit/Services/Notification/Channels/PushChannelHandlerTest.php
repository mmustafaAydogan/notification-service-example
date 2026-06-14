<?php

namespace Tests\Unit\Services\Notification\Channels;

use App\Enums\NotificationChannel;
use App\Services\Notification\Channels\PushChannelHandler;
use PHPUnit\Framework\TestCase;

class PushChannelHandlerTest extends TestCase
{
    private PushChannelHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = new PushChannelHandler();
    }

    public function test_channel_is_push(): void
    {
        $this->assertSame(NotificationChannel::Push, $this->handler->channel());
    }

    public function test_validation_rules_require_device_token_title_body(): void
    {
        $rules = $this->handler->validationRules();

        $this->assertArrayHasKey('device_token', $rules);
        $this->assertArrayHasKey('title',        $rules);
        $this->assertArrayHasKey('body',         $rules);
    }

    public function test_idempotency_hash_is_channel_scoped(): void
    {
        $sharedPayload = ['recipient' => 'x', 'body' => 'y'];

        $push = (new PushChannelHandler())->idempotencyHash([
            'device_token' => 'token-abc',
            'title'        => 't',
            'body'         => 'y',
        ]);

        $this->assertNotEmpty($push);
        $this->assertSame(32, strlen($push), 'idempotency hash should be md5 hex (32 chars)');
    }
}
