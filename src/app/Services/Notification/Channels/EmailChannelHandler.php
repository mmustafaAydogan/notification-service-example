<?php

namespace App\Services\Notification\Channels;

use App\Enums\NotificationChannel;
use App\Models\EmailNotification;
use App\Services\Notification\Channels\Contracts\ChannelHandler;
use Illuminate\Support\Str;

class EmailChannelHandler implements ChannelHandler
{
    public function channel(): NotificationChannel
    {
        return NotificationChannel::Email;
    }

    public function validationRules(): array
    {
        return [
            'recipient' => ['required', 'email'],
            'subject'   => ['required', 'string', 'max:255'],
            'body'      => ['required', 'string', 'max:10000'],
        ];
    }

    public function idempotencyHash(array $data): string
    {
        return md5(implode('|', [$data['recipient'], $data['subject'], $data['body'], $this->channel()->value]));
    }

    public function persistDetail(string $notificationId, array $data): void
    {
        EmailNotification::create([
            'notification_id' => $notificationId,
            'recipient'       => $data['recipient'],
            'subject'         => $data['subject'],
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
            'recipient'       => $item['data']['recipient'],
            'subject'         => $item['data']['subject'],
            'body'            => $item['data']['body'],
        ], $items);

        EmailNotification::insert($rows);
    }

    public function payloadFromNotification(\App\Models\Notification $notification): array
    {
        return [
            'recipient' => $notification->emailNotification->recipient,
            'subject'   => $notification->emailNotification->subject,
            'body'      => $notification->emailNotification->body,
        ];
    }
}
