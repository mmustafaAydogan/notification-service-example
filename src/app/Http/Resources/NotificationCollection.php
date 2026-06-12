<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema    : 'NotificationSummary',
    description: 'Lightweight notification representation used in list responses. Use GET /notifications/{id} for the channel-specific `detail` payload.',
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
    ],
    type      : 'object',
)]
#[OA\Schema(
    schema    : 'NotificationCollection',
    properties: [
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/NotificationSummary')),
        new OA\Property(
            property  : 'meta',
            properties: [
                new OA\Property(property: 'current_page', type: 'integer'),
                new OA\Property(property: 'from', type: 'integer', nullable: true),
                new OA\Property(property: 'last_page', type: 'integer'),
                new OA\Property(property: 'per_page', type: 'integer'),
                new OA\Property(property: 'to', type: 'integer', nullable: true),
                new OA\Property(property: 'total', type: 'integer'),
            ],
            type      : 'object',
        ),
    ],
    type      : 'object',
)]
class NotificationCollection extends ResourceCollection
{
    public $collects = NotificationResource::class;

    public function paginationInformation(Request $request, array $paginated, array $default): array
    {
        unset($default['links'], $default['meta']['links'], $default['meta']['path']);

        return $default;
    }
}
