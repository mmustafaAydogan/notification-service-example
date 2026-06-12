<?php

namespace App\Providers;

use App\Contracts\NotificationProvider;
use App\Services\Notification\Channels\ChannelHandlerRegistry;
use App\Services\Notification\Channels\EmailChannelHandler;
use App\Services\Notification\Channels\PushChannelHandler;
use App\Services\Notification\Channels\SmsChannelHandler;
use App\Services\WebhookProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    private const CHANNEL_HANDLERS_TAG = 'notification.channel_handlers';

    public function register(): void
    {
        $this->app->bind(NotificationProvider::class, WebhookProvider::class);

        $this->app->tag([
            SmsChannelHandler::class,
            EmailChannelHandler::class,
            PushChannelHandler::class,
        ], self::CHANNEL_HANDLERS_TAG);

        $this->app->singleton(
            ChannelHandlerRegistry::class,
            fn($app) => new ChannelHandlerRegistry($app->tagged(self::CHANNEL_HANDLERS_TAG)),
        );
    }

    public function boot(): void
    {
    }
}
