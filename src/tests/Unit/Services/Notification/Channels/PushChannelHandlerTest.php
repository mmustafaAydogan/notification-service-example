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
        $payload = [
            'device_token' => 'token-abc',
            'title'        => 't',
            'body'         => 'y',
        ];

        $hash = $this->handler->idempotencyHash($payload);

        // Deterministic md5 hex digest.
        $this->assertSame(32, strlen($hash), 'idempotency hash should be md5 hex (32 chars)');
        $this->assertSame($hash, $this->handler->idempotencyHash($payload));

        // The channel value participates in the digest: the same fields hashed
        // without the 'push' suffix must differ, so a push key can never
        // collide with another channel's key built from identical values.
        $withoutChannel = md5(implode('|', [$payload['device_token'], $payload['title'], $payload['body']]));
        $this->assertNotSame($withoutChannel, $hash);
    }
}
