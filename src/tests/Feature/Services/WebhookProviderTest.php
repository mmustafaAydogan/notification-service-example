<?php

namespace Tests\Feature\Services;

use App\Contracts\NotificationProvider;
use App\Enums\NotificationChannel;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebhookProviderTest extends TestCase
{
    public function test_send_returns_provider_response_with_message_id(): void
    {
        Http::fake(['*' => Http::response(['messageId' => 'abc-123'], 200)]);

        $response = $this->provider()->send(NotificationChannel::Sms, [
            'recipient' => '+905551112233',
            'content'   => 'hi',
        ]);

        $this->assertSame('abc-123', $response->messageId);
    }

    public function test_send_posts_payload_with_channel_merged_in(): void
    {
        Http::fake(['*' => Http::response(['messageId' => 'x'], 200)]);

        $this->provider()->send(NotificationChannel::Email, ['recipient' => 'user@example.com']);

        Http::assertSent(fn ($request) => $request['channel'] === 'email'
            && $request['recipient'] === 'user@example.com');
    }

    public function test_send_throws_422_when_provider_rejects_payload(): void
    {
        Http::fake(['*' => Http::response('invalid', 422)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(422);

        $this->provider()->send(NotificationChannel::Sms, [
            'recipient' => '+905551112233',
            'content'   => 'hi',
        ]);
    }

    public function test_send_throws_on_server_error(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);

        $this->expectException(\RuntimeException::class);

        $this->provider()->send(NotificationChannel::Sms, [
            'recipient' => '+905551112233',
            'content'   => 'hi',
        ]);
    }

    private function provider(): NotificationProvider
    {
        return app(NotificationProvider::class);
    }
}
