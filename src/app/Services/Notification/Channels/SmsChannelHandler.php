<?php

namespace App\Services\Notification\Channels;

use App\Enums\NotificationChannel;
use App\Models\SmsNotification;
use App\Services\Notification\Channels\Contracts\ChannelHandler;
use Illuminate\Support\Str;

class SmsChannelHandler implements ChannelHandler
{
    public function channel(): NotificationChannel
    {
        return NotificationChannel::Sms;
    }

    public function validationRules(): array
    {
        return [
            'recipient' => ['required', 'string', 'regex:/^\+[1-9]\d{7,14}$/'],
            'content'   => ['required', 'string', 'max:160'],
        ];
    }

    public function idempotencyHash(array $data): string
    {
        return md5(implode('|', [$data['recipient'], $data['content'], $this->channel()->value]));
    }

    public function persistDetail(string $notificationId, array $data): void
    {
        SmsNotification::create([
            'notification_id' => $notificationId,
            'recipient'       => $data['recipient'],
            'content'         => $data['content'],
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
            'recipient'       => $item['data']['recipient'],
            'content'         => $item['data']['content'],
        ], $items);

        SmsNotification::insert($rows);
    }
}
