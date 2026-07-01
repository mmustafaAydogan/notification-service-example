<?php

namespace App\Http\Requests\Api;

use App\Enums\NotificationChannel;
use App\Services\Notification\Channels\ChannelHandlerRegistry;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SendEmailRequest',
    required: ['recipient', 'subject', 'body'],
    properties: [
        new OA\Property(property: 'recipient', type: 'string', format: 'email', example: 'mustafa@example.com'),
        new OA\Property(property: 'subject',   type: 'string', example: 'Siparişiniz Onaylandı', maxLength: 255),
        new OA\Property(property: 'body',      type: 'string', example: 'Sayın müşterimiz, siparişiniz başarıyla onaylanmıştır.',maxLength: 10000),
        new OA\Property(property: 'priority',  description: 'Message Priority', type: 'string',enum:['Low', 'Medium', 'High']),
        new OA\Property(property: 'scheduled_at', description: 'Scheduled delivery time', type: 'string', example: '2026-07-01 09:00', nullable: true),
    ],
    type: 'object',
)]
class SendEmailNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return app(ChannelHandlerRegistry::class)->rulesFor(NotificationChannel::Email);
    }
}
