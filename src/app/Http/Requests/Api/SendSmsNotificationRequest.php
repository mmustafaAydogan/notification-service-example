<?php

namespace App\Http\Requests\Api;

use App\Enums\NotificationChannel;
use App\Services\Notification\Channels\ChannelHandlerRegistry;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SendSmsRequest',
    required: ['recipient', 'content'],
    properties: [
        new OA\Property(property: 'recipient', description: 'E.164 formatted phone number', type: 'string', example: '+905551234567', maxLength: 16),
        new OA\Property(property: 'content',   type: 'string', example: 'Merhaba, siparişiniz hazır.', maxLength: 160),
        new OA\Property(property: 'priority',  description: 'Message Priority', type: 'string', enum:['Low', 'Medium', 'High']),
    ],
    type: 'object',
)]
class SendSmsNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return app(ChannelHandlerRegistry::class)->rulesFor(NotificationChannel::Sms);
    }
}
