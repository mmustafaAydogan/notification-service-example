<?php

namespace Tests\Feature\Providers;

use App\Contracts\NotificationProvider;
use App\Services\WebhookProvider;
use Tests\TestCase;

class AppServiceProviderBindingTest extends TestCase
{
    public function test_notification_provider_resolves_to_webhook_provider(): void
    {
        $this->assertInstanceOf(WebhookProvider::class, app(NotificationProvider::class));
    }

    public function test_notification_provider_is_registered_as_singleton(): void
    {
        $this->assertSame(
            app(NotificationProvider::class),
            app(NotificationProvider::class),
        );
    }
}
