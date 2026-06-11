<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class BulkNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'notifications'   => ['required', 'array', 'min:1', 'max:1000'],
            'notifications.*' => ['required', 'array'],
        ];
    }
}
