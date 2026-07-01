<?php

namespace App\Services\Notification\Channels;

use App\Enums\NotificationChannel;
use App\Services\Notification\Channels\Contracts\ChannelHandler;
use InvalidArgumentException;
use Illuminate\Validation\Rule;
use App\Enums\PriorityStatus;

class ChannelHandlerRegistry
{
    /** @var array<string, ChannelHandler> */
    private array $handlers = [];

    public function __construct(iterable $handlers)
    {
        foreach ($handlers as $handler) {
            $this->handlers[$handler->channel()->value] = $handler;
        }
    }

    public function handlerFor(NotificationChannel $channel): ChannelHandler
    {
        return $this->handlers[$channel->value]
            ?? throw new InvalidArgumentException("No handler registered for channel: {$channel->value}");
    }

    public function rulesFor(NotificationChannel $channel): array
    {
        return $this->handlerFor($channel)->validationRules() + self::commonRules();
    }

    public static function commonRules(): array
    {
        return [
            'priority'     => ['sometimes',  Rule::enum(PriorityStatus::class)],
            'batch_id'     => ['sometimes', 'uuid'],
            'scheduled_at' => ['sometimes', 'date_format:Y-m-d H:i', 'after:now'],
        ];
    }
}
