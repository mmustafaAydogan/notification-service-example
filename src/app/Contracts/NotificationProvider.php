<?php

namespace App\Contracts;

use App\Enums\NotificationChannel;

interface NotificationProvider
{
    public function send(NotificationChannel $channel, array $payload): ProviderResponse;
}
