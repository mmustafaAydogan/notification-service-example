<?php

namespace App\Http\Resources;

use App\Enums\NotificationChannel;
use App\Enums\PriorityStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema    : 'NotificationResource',
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'channel', ref: '#/components/schemas/NotificationChannel'),
        new OA\Property(property: 'priority', type: 'string', example: 'Medium', enum: ['Low', 'Medium', 'High']),
        new OA\Property(property: 'status', ref: '#/components/schemas/NotificationStatus'),
        new OA\Property(property: 'batch_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'provider_message_id', type: 'string', example: 'twilio_SMxxxx', nullable: true),
        new OA\Property(property: 'attempts', type: 'integer', example: 0),
        new OA\Property(property: 'scheduled_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'sent_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
        new OA\Property(
            property   : 'detail',
            description: 'Channel-specific payload. Shape depends on `channel`. Omitted when relation is not eager-loaded (e.g. index endpoint).',
            type       : 'object',
            oneOf      : [
                new OA\Schema(
                    title     : 'SmsDetail',
                    properties: [
                        new OA\Property(property: 'recipient', type: 'string'),
                        new OA\Property(property: 'content', type: 'string'),
                    ],
                ),
                new OA\Schema(
                    title     : 'EmailDetail',
                    properties: [
                        new OA\Property(property: 'recipient', type: 'string', format: 'email'),
                        new OA\Property(property: 'subject', type: 'string'),
                        new OA\Property(property: 'body', type: 'string'),
                    ],
                ),
                new OA\Schema(
                    title     : 'PushDetail',
                    properties: [
                        new OA\Property(property: 'device_token', type: 'string'),
                        new OA\Property(property: 'title', type: 'string'),
                        new OA\Property(property: 'body', type: 'string'),
                    ],
                ),
            ],
        ),
    ],
    type      : 'object',
)]
#[OA\Schema(
    schema    : 'BulkRequest',
    required  : ['notifications'],
    properties: [
        new OA\Property(
            property: 'notifications',
            type    : 'array',
            items   : new OA\Items(
                required  : ['channel'],
                properties: [
                    new OA\Property(property: 'channel', ref: '#/components/schemas/NotificationChannel'),
                    new OA\Property(property: 'recipient', description: 'For sms/email', type: 'string', nullable: true),
                    new OA\Property(property: 'content', description: 'For sms', type: 'string', nullable: true),
                    new OA\Property(property: 'subject', description: 'For email', type: 'string', nullable: true),
                    new OA\Property(property: 'body', description: 'For email/push', type: 'string', nullable: true),
                    new OA\Property(property: 'device_token', description: 'For push', type: 'string', nullable: true),
                    new OA\Property(property: 'title', description: 'For push', type: 'string', nullable: true),
                    new OA\Property(property: 'priority', type: 'string', enum: ['Low', 'Medium', 'High'], nullable: true),
                ],
                type      : 'object',
            ),
            maxItems: 1000,
            minItems: 1,
        ),
    ],
    type      : 'object',
)]
#[OA\Schema(
    schema    : 'BulkResponse',
    required  : ['batch_id', 'accepted', 'rejected', 'errors'],
    properties: [
        new OA\Property(property: 'batch_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'accepted', type: 'integer', example: 2),
        new OA\Property(property: 'rejected', type: 'integer', example: 1),
        new OA\Property(
            property: 'errors',
            type    : 'array',
            items   : new OA\Items(
                properties: [
                    new OA\Property(property: 'index', type: 'integer'),
                    new OA\Property(
                        property   : 'reason',
                        description: 'Sentinel values: "duplicate" (idempotency hit), "concurrent_write_conflict" (race on bulk insert). Otherwise a validation error message.',
                        type       : 'string',
                        example    : 'duplicate',
                    ),
                ],
                type      : 'object',
            ),
        ),
    ],
    type      : 'object',
)]
class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = [
            'id'                  => $this->id,
            'channel'             => $this->channel,
            'priority'            => PriorityStatus::fromInt((int)$this->priority)->value,
            'status'              => $this->status,
            'batch_id'            => $this->batch_id,
            'provider_message_id' => $this->provider_message_id,
            'attempts'            => $this->attempts,
            'scheduled_at'        => $this->scheduled_at?->toIso8601String(),
            'sent_at'             => $this->sent_at?->toIso8601String(),
            'created_at'          => $this->created_at->toIso8601String(),
        ];

        if (($detail = $this->resolveDetail()) !== null) {
            $data['detail'] = $detail;
        }

        return $data;
    }


    private function resolveDetail(): ?array
    {
        return match ($this->channel) {
            NotificationChannel::Sms => $this->relationLoaded('smsNotification') && $this->smsNotification
                ? [
                    'recipient' => $this->smsNotification->recipient,
                    'content'   => $this->smsNotification->content,
                ]
                : null,
            NotificationChannel::Email => $this->relationLoaded('emailNotification') && $this->emailNotification
                ? [
                    'recipient' => $this->emailNotification->recipient,
                    'subject'   => $this->emailNotification->subject,
                    'body'      => $this->emailNotification->body,
                ]
                : null,
            NotificationChannel::Push => $this->relationLoaded('pushNotification') && $this->pushNotification
                ? [
                    'device_token' => $this->pushNotification->device_token,
                    'title'        => $this->pushNotification->title,
                    'body'         => $this->pushNotification->body,
                ]
                : null,
            default => null,
        };
    }
}
