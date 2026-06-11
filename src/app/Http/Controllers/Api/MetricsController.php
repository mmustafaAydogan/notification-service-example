<?php

namespace App\Http\Controllers\Api;

use App\Enums\NotificationStatus;
use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

class MetricsController extends Controller
{
    #[OA\Get(
        path     : '/api/metrics',
        summary  : 'Aggregate notification counts and success/failure rates',
        tags     : ['System'],
        responses: [
            new OA\Response(
                response   : 200,
                description: 'OK',
                content    : new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property  : 'notifications',
                            properties: [
                                new OA\Property(property: 'total', type: 'integer', example: 1024),
                                new OA\Property(
                                    property  : 'by_status',
                                    properties: [
                                        new OA\Property(property: 'pending', type: 'integer'),
                                        new OA\Property(property: 'processing', type: 'integer'),
                                        new OA\Property(property: 'sent', type: 'integer'),
                                        new OA\Property(property: 'failed', type: 'integer'),
                                        new OA\Property(property: 'cancelled', type: 'integer'),
                                    ],
                                    type      : 'object',
                                ),
                                new OA\Property(
                                    property  : 'by_channel',
                                    properties: [
                                        new OA\Property(property: 'sms', type: 'integer'),
                                        new OA\Property(property: 'email', type: 'integer'),
                                        new OA\Property(property: 'push', type: 'integer'),
                                    ],
                                    type      : 'object',
                                ),
                            ],
                            type      : 'object',
                        ),
                        new OA\Property(
                            property  : 'rates',
                            properties: [
                                new OA\Property(property: 'success_rate', type: 'number', format: 'float', example: 98.5),
                                new OA\Property(property: 'failure_rate', type: 'number', format: 'float', example: 1.5),
                            ],
                            type      : 'object',
                        ),
                        new OA\Property(
                            property  : 'queue',
                            properties: [
                                new OA\Property(property: 'driver', type: 'string', example: 'rabbitmq'),
                                new OA\Property(property: 'name', type: 'string', example: 'notifications'),
                                new OA\Property(property: 'depth', type: 'integer', example: 42, nullable: true, description: 'Pending message count; null if depth cannot be read'),
                            ],
                            type      : 'object',
                        ),
                        new OA\Property(
                            property   : 'latency',
                            description: 'End-to-end seconds between created_at and sent_at for status=sent',
                            properties : [
                                new OA\Property(property: 'avg_seconds', type: 'number', format: 'float', example: 3.21, nullable: true),
                                new OA\Property(property: 'sample_size', type: 'integer', example: 1000),
                            ],
                            type       : 'object',
                        ),
                    ],
                    type      : 'object',
                ),
            ),
        ],
    )]
    public function __invoke(): JsonResponse
    {
        $byStatus = Notification::select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $byChannel = Notification::select('channel', DB::raw('COUNT(*) as count'))
            ->groupBy('channel')
            ->pluck('count', 'channel')
            ->toArray();

        $total = array_sum($byStatus);
        $sent = $byStatus['sent'] ?? 0;
        $failed = $byStatus['failed'] ?? 0;

        $latency = $this->latency();

        return response()->json([
            'notifications' => [
                'total'      => $total,
                'by_status'  => [
                    'pending'    => $byStatus['pending'] ?? 0,
                    'processing' => $byStatus['processing'] ?? 0,
                    'sent'       => $sent,
                    'failed'     => $failed,
                    'cancelled'  => $byStatus['cancelled'] ?? 0,
                ],
                'by_channel' => [
                    'sms'   => $byChannel['sms'] ?? 0,
                    'email' => $byChannel['email'] ?? 0,
                    'push'  => $byChannel['push'] ?? 0,
                ],
            ],
            'rates'         => [
                'success_rate' => $total > 0 ? round($sent / $total * 100, 2) : 0,
                'failure_rate' => $total > 0 ? round($failed / $total * 100, 2) : 0,
            ],
            'queue'         => $this->queue(),
            'latency'       => $latency,
        ]);
    }

    private function queue(): array
    {
        $driver = config('queue.default');

        return [
            'driver' => $driver,
            'name'   => $this->queueName($driver),
            'depth'  => $this->queueDepth($driver),
        ];
    }

    private function queueName(string $driver): string
    {
        return match ($driver) {
            'rabbitmq' => (string)config('queue.connections.rabbitmq.queue', 'notifications'),
            'database' => (string)config('queue.connections.database.queue', 'default'),
            'redis' => (string)config('queue.connections.redis.queue', 'default'),
            default => 'notifications',
        };
    }

    private function queueDepth(string $driver): ?int
    {
        return match ($driver) {
            'database' => $this->databaseQueueDepth(),
            'rabbitmq' => $this->rabbitMqQueueDepth(),
            default => null,
        };
    }

    private function databaseQueueDepth(): ?int
    {
        $table = config('queue.connections.database.table', 'jobs');
        $queues = array_unique(['notifications', $this->queueName('database')]);

        return DB::table($table)
            ->whereIn('queue', $queues)
            ->count();
    }

    private function rabbitMqQueueDepth(): ?int
    {
        $scheme = config('queue.connections.rabbitmq.management.scheme', 'http');
        $host = config('queue.connections.rabbitmq.management.host');
        $port = config('queue.connections.rabbitmq.management.port', 15672);
        $vhost = rawurlencode((string)config('queue.connections.rabbitmq.hosts.0.vhost', '/'));
        $queue = $this->queueName('rabbitmq');
        $user = config('queue.connections.rabbitmq.hosts.0.user');
        $pass = config('queue.connections.rabbitmq.hosts.0.password');

        try {
            $response = Http::withBasicAuth($user, $pass)
                ->timeout(2)
                ->get("{$scheme}://{$host}:{$port}/api/queues/{$vhost}/{$queue}");

            if (!$response->successful()) {
                return null;
            }

            return (int)($response->json('messages') ?? 0);
        } catch (ConnectionException $e) {
            Log::warning('RabbitMQ management API unreachable for queue depth', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function latency(): array
    {
        $row = Notification::query()
            ->where('status', NotificationStatus::Sent)
            ->whereNotNull('sent_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(SECOND, created_at, sent_at)) as avg_seconds, COUNT(*) as sample_size')
            ->first();

        $avg = $row?->avg_seconds;
        $sample = (int)($row?->sample_size ?? 0);

        return [
            'avg_seconds' => $avg !== null ? round((float)$avg, 2) : null,
            'sample_size' => $sample,
        ];
    }
}
