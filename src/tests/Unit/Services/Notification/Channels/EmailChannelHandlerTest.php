<?php

namespace Tests\Unit\Services\Notification\Channels;

use App\Enums\NotificationChannel;
use App\Services\Notification\Channels\EmailChannelHandler;
use PHPUnit\Framework\TestCase;

class EmailChannelHandlerTest extends TestCase
{
    private EmailChannelHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = new EmailChannelHandler();
    }

    public function test_channel_is_email(): void
    {
        $this->assertSame(NotificationChannel::Email, $this->handler->channel());
    }

    public function test_validation_rules_require_recipient_subject_body(): void
    {
        $rules = $this->handler->validationRules();

        $this->assertArrayHasKey('recipient', $rules);
        $this->assertArrayHasKey('subject',   $rules);
        $this->assertArrayHasKey('body',      $rules);
        $this->assertContains('email', $rules['recipient']);
    }

    public function test_idempotency_hash_separates_subject_and_body(): void
    {
        $base = [
            'recipient' => 'a@example.com',
            'subject'   => 'Hello',
            'body'      => 'Body',
        ];

        $hash = $this->handler->idempotencyHash($base);

        $this->assertSame($hash, $this->handler->idempotencyHash($base));
        $this->assertNotSame($hash, $this->handler->idempotencyHash([...$base, 'subject' => 'World']));
        $this->assertNotSame($hash, $this->handler->idempotencyHash([...$base, 'body'    => 'Different']));
    }
}
