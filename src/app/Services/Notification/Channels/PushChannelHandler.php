<?php

namespace App\Services\Notification\Channels;

use App\Enums\NotificationChannel;
use App\Models\PushNotification;
use App\Services\Notification\Channels\Contracts\ChannelHandler;
use Illuminate\Support\Str;

class PushChannelHandler implements ChannelHandler
{
    public function channel(): NotificationChannel
    {
        return NotificationChannel::Push;
    }

    public function validationRules(): array
    {
        return [
            'device_token' => ['required', 'string', 'min:10'],
            'title'        => ['required', 'string', 'max:255'],
            'body'         => ['required', 'string', 'max:256'],
        ];
    }

    public function idempotencyHash(array $data): string
    {
        return md5(implode('|', [$data['device_token'], $data['title'], $data['body'], $this->channel()->value]));
    }

    public function persistDetail(string $notificationId, array $data): void
    {
        PushNotification::create([
            'notification_id' => $notificationId,
            'device_token'    => $data['device_token'],
            'title'           => $data['title'],
            'body'            => $data['body'],
        ]);
    }

    public function persistDetailsBatch(array $items): void
    {
        if (empty($items)) {
            return;
        }

        $rows = array_map(fn (array $item) => [
            'id'              => Str::uuid()->toString(),
            'notification_id' => $item['notification_id'],
            'device_token'    => $item['data']['device_token'],
            'title'           => $item['data']['title'],
            'body'            => $item['data']['body'],
        ], $items);

        PushNotification::insert($rows);
    }
}
