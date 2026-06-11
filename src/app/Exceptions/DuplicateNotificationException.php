<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DuplicateNotificationException extends HttpException
{
    public function __construct(public readonly string $existingNotificationId)
    {
        parent::__construct(
            statusCode: Response::HTTP_CONFLICT,
            message: 'Notification already exists with this idempotency key',
        );
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message'                  => $this->getMessage(),
            'existing_notification_id' => $this->existingNotificationId,
        ], $this->getStatusCode());
    }
}
