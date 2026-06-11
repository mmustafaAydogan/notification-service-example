<?php

namespace App\Http\Controllers\Api;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\BulkNotificationRequest;
use App\Http\Requests\Api\SendEmailNotificationRequest;
use App\Http\Requests\Api\SendPushNotificationRequest;
use App\Http\Requests\Api\SendSmsNotificationRequest;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use App\Services\BulkNotificationService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

class NotificationController extends Controller
{
    public function __construct(
        private NotificationService  $notificationService,
        private BulkNotificationService $bulkService,
    ) {}

    #[OA\Post(
        path: '/api/notifications/sms',
        summary: 'Send an SMS notification',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SendSmsRequest')),
        tags: ['Notifications'],
        responses: [
            new OA\Response(response: 202, description: 'Accepted for async dispatch', content: new OA\JsonContent(ref: '#/components/schemas/AcceptedNotification')),
            new OA\Response(response: 409, description: 'Duplicate (idempotency hit)',  content: new OA\JsonContent(ref: '#/components/schemas/DuplicateNotificationError')),
            new OA\Response(response: 422, description: 'Validation error',             content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function sendSms(SendSmsNotificationRequest $request): JsonResponse
    {
        $result = $this->notificationService->send(NotificationChannel::Sms, $request->validated());

        return response()->json($result, 202);
    }

    #[OA\Post(
        path: '/api/notifications/email',
        summary: 'Send an email notification',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SendEmailRequest')),
        tags: ['Notifications'],
        responses: [
            new OA\Response(response: 202, description: 'Accepted for async dispatch', content: new OA\JsonContent(ref: '#/components/schemas/AcceptedNotification')),
            new OA\Response(response: 409, description: 'Duplicate (idempotency hit)',  content: new OA\JsonContent(ref: '#/components/schemas/DuplicateNotificationError')),
            new OA\Response(response: 422, description: 'Validation error',             content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function sendEmail(SendEmailNotificationRequest $request): JsonResponse
    {
        $result = $this->notificationService->send(NotificationChannel::Email, $request->validated());

        return response()->json($result, 202);
    }

    #[OA\Post(
        path: '/api/notifications/push',
        summary: 'Send a push notification',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SendPushRequest')),
        tags: ['Notifications'],
        responses: [
            new OA\Response(response: 202, description: 'Accepted for async dispatch', content: new OA\JsonContent(ref: '#/components/schemas/AcceptedNotification')),
            new OA\Response(response: 409, description: 'Duplicate (idempotency hit)',  content: new OA\JsonContent(ref: '#/components/schemas/DuplicateNotificationError')),
            new OA\Response(response: 422, description: 'Validation error',             content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function sendPush(SendPushNotificationRequest $request): JsonResponse
    {
        $result = $this->notificationService->send(NotificationChannel::Push, $request->validated());

        return response()->json($result, 202);
    }

    #[OA\Post(
        path: '/api/notifications/bulk',
        summary: 'Send a batch of mixed-channel notifications (max 1000)',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/BulkRequest')),
        tags: ['Notifications'],
        responses: [
            new OA\Response(response: 202, description: 'Batch accepted', content: new OA\JsonContent(ref: '#/components/schemas/BulkResponse')),
            new OA\Response(response: 422, description: 'Validation error',  content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function bulk(BulkNotificationRequest $request): JsonResponse
    {
        $result = $this->bulkService->send($request->validated('notifications'));

        return response()->json($result, 202);
    }

    #[OA\Get(
        path: '/api/notifications',
        summary: 'List notifications (paginated, filterable)',
        tags: ['Notifications'],
        parameters: [
            new OA\Parameter(name: 'status',   in: 'query', required: false, schema: new OA\Schema(ref: '#/components/schemas/NotificationStatus')),
            new OA\Parameter(name: 'channel',  in: 'query', required: false, schema: new OA\Schema(ref: '#/components/schemas/NotificationChannel')),
            new OA\Parameter(name: 'batch_id', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'from',     description: 'created_at lower bound (YYYY-MM-DD)', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to',       description: 'created_at upper bound (YYYY-MM-DD)', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20, maximum: 100, minimum: 1)),
            new OA\Parameter(name: 'page',     in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1, minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/NotificationCollection')),
        ],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Notification::query();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('channel')) {
            $query->where('channel', $request->channel);
        }

        if ($request->filled('batch_id')) {
            $query->where('batch_id', $request->batch_id);
        }

        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->from);
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->to);
        }

        $perPage       = min((int) $request->input('per_page', 20), 100);
        $notifications = $query->orderByDesc('created_at')->paginate($perPage);

        return NotificationResource::collection($notifications);
    }

    #[OA\Get(
        path: '/api/notifications/{id}',
        summary: 'Get a single notification with channel-specific detail',
        tags: ['Notifications'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'OK', content: new OA\JsonContent(ref: '#/components/schemas/NotificationResource')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function show(string $id): JsonResponse
    {
        $notification = Notification::with([
            'smsNotification',
            'emailNotification',
            'pushNotification',
        ])->findOrFail($id);

        return response()->json(new NotificationResource($notification));
    }

    #[OA\Post(
        path: '/api/notifications/cancel/{id}',
        summary: 'Cancel a pending or processing notification',
        tags: ['Notifications'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Cancelled',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'id',         type: 'string', format: 'uuid'),
                        new OA\Property(property: 'status',     ref: '#/components/schemas/NotificationStatus'),
                        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(
                response: 409,
                description: 'Not cancellable (terminal status)',
                content: new OA\JsonContent(
                    required: ['message'],
                    properties: [
                        new OA\Property(property: 'message', type: 'string', example: "Notification with status 'sent' cannot be cancelled."),
                    ],
                    type: 'object',
                ),
            ),
        ],
    )]
    public function cancel(string $id): JsonResponse
    {
        $notification = Notification::findOrFail($id);

        if (!$notification->status->isCancellable()) {
            return response()->json([
                'message' => "Notification with status '{$notification->status->value}' cannot be cancelled.",
            ], 409);
        }

        $notification->update(['status' => NotificationStatus::Cancelled]);

        return response()->json([
            'id'         => $notification->id,
            'status'     => $notification->status,
            'updated_at' => $notification->updated_at,
        ], 200);
    }

    #[OA\Post(
        path: '/api/notifications/cancel/batch/{batchId}',
        summary: 'Cancel all cancellable notifications in a batch',
        tags: ['Notifications'],
        parameters: [
            new OA\Parameter(name: 'batchId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'IDs of cancelled notifications',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'cancelled',
                            type: 'array',
                            items: new OA\Items(type: 'string', format: 'uuid'),
                        ),
                    ],
                    type: 'object',
                ),
            ),
        ],
    )]
    public function cancelBatch(string $batchId): JsonResponse
    {
        $cancellable = Notification::inBatch($batchId)->cancellable();

        $ids = $cancellable->pluck('id');

        Notification::whereIn('id', $ids)
            ->update(['status' => NotificationStatus::Cancelled]);

        return response()->json(['cancelled' => $ids], 200);
    }

}
