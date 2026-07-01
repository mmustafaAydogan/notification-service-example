<?php

namespace App\Http\Requests\Api;

use App\Enums\NotificationChannel;
use App\Services\Notification\Channels\ChannelHandlerRegistry;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SendPushRequest',
    required: ['device_token', 'title', 'body'],
    properties: [
        new OA\Property(property: 'device_token', type: 'string', example: 'device-token-abc123-xyz789-mobile', minLength: 10),
        new OA\Property(property: 'title',        type: 'string', example: 'Siparişiniz Yolda', maxLength: 255),
        new OA\Property(property: 'body',         type: 'string', example: 'Kargonuz bugün teslim edilecek.', maxLength: 256),
        new OA\Property(property: 'priority',     description: 'Message Priority', type: 'string',enum:['Low', 'Medium', 'High']),
        new OA\Property(property: 'scheduled_at', description: 'Scheduled delivery time', type: 'string', example: '2026-07-01 09:00', nullable: true),
    ],
    type: 'object',
)]
class SendPushNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return app(ChannelHandlerRegistry::class)->rulesFor(NotificationChannel::Push);
    }
}
