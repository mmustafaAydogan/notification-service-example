<?php

namespace App\Http\Controllers;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    title: 'Notification API',
    description: 'Multi-channel (SMS / Email / Push) notification dispatch service with batch support, idempotency and async processing.',
    contact: new OA\Contact(name: 'In01', email: 'support@in01.local'),
)]
#[OA\Server(url: 'http://localhost:8080', description: 'Local')]
#[OA\Tag(name: 'Notifications', description: 'Send, list, inspect and cancel notifications')]
#[OA\Tag(name: 'System',        description: 'Health and metrics endpoints')]
#[OA\Schema(
    schema: 'ValidationError',
    required: ['message', 'errors'],
    properties: [
        new OA\Property(property: 'message', type: 'string', example: 'The given data was invalid.'),
        new OA\Property(
            property: 'errors',
            type: 'object',
            example: ['recipient' => ['The recipient field is required.']],
            additionalProperties: new OA\AdditionalProperties(type: 'array', items: new OA\Items(type: 'string')),
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'ErrorResponse',
    required: ['message'],
    properties: [
        new OA\Property(property: 'message', type: 'string', example: 'Resource not found.'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'DuplicateNotificationError',
    required: ['message', 'existing_notification_id'],
    properties: [
        new OA\Property(property: 'message',                  type: 'string', example: 'Notification already exists with this idempotency key'),
        new OA\Property(property: 'existing_notification_id', type: 'string', format: 'uuid'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'AcceptedNotification',
    required: ['id', 'status', 'created_at'],
    properties: [
        new OA\Property(property: 'id',         type: 'string', format: 'uuid', example: '0a3b2f9c-2bb0-4d7c-8f0f-7c1d5e2c1f0a'),
        new OA\Property(property: 'status',     ref: '#/components/schemas/NotificationStatus'),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'NotificationChannel',
    type: 'string',
    enum: ['sms', 'email', 'push'],
)]
#[OA\Schema(
    schema: 'NotificationStatus',
    type: 'string',
    enum: ['pending', 'processing', 'sent', 'failed', 'cancelled'],
)]
abstract class Controller
{
    //
}
