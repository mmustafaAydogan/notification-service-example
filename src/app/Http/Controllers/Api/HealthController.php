<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use OpenApi\Attributes as OA;

class HealthController extends Controller
{
    #[OA\Get(
        path     : '/api/health',
        summary  : 'System health check (DB, Redis, RabbitMQ)',
        tags     : ['System'],
        responses: [
            new OA\Response(
                response   : 200,
                description: 'All dependencies healthy',
                content    : new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'status', type: 'string', enum: ['healthy', 'unhealthy'], example: 'healthy'),
                        new OA\Property(
                            property  : 'checks',
                            properties: [
                                new OA\Property(property: 'database', properties: [new OA\Property(property: 'status', type: 'string')], type: 'object'),
                                new OA\Property(property: 'redis', properties: [new OA\Property(property: 'status', type: 'string')], type: 'object'),
                                new OA\Property(property: 'rabbitmq', properties: [new OA\Property(property: 'status', type: 'string')], type: 'object'),
                            ],
                            type      : 'object',
                        ),
                    ],
                    type      : 'object',
                ),
            ),
            new OA\Response(response: 503, description: 'At least one dependency is unhealthy',
                            content : new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'status', type: 'string', enum: ['healthy', 'unhealthy'], example: 'healthy'),
                        new OA\Property(
                            property  : 'checks',
                            properties: [
                                new OA\Property(property: 'database', properties: [new OA\Property(property: 'status', type: 'string')], type: 'object'),
                                new OA\Property(property: 'redis', properties: [new OA\Property(property: 'status', type: 'string')], type: 'object'),
                                new OA\Property(property: 'rabbitmq', properties: [new OA\Property(property: 'status', type: 'string')], type: 'object'),
                            ],
                            type      : 'object',
                        ),
                    ],
                    type      : 'object',
                ),
            ),
        ],
    )]
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'redis'    => $this->checkRedis(),
            'rabbitmq' => $this->checkRabbitMQ(),
        ];

        $healthy = !in_array('unhealthy', array_column($checks, 'status'));

        return response()->json([
            'status' => $healthy ? 'healthy' : 'unhealthy',
            'checks' => $checks,
        ], $healthy ? 200 : 503);
    }

    private function checkDatabase(): array
    {
        try {
            DB::selectOne('SELECT 1');
            return ['status' => 'healthy'];
        } catch (\Throwable $e) {
            return ['status' => 'unhealthy', 'error' => $e->getMessage()];
        }
    }

    private function checkRedis(): array
    {
        try {
            Redis::ping();
            return ['status' => 'healthy'];
        } catch (\Throwable $e) {
            return ['status' => 'unhealthy', 'error' => $e->getMessage()];
        }
    }

    private function checkRabbitMQ(): array
    {
        try {
            $connection = app('queue')->connection('rabbitmq');
            $connection->getQueue(null, config('queue.connections.rabbitmq.queue', 'notifications'));
            return ['status' => 'healthy'];
        } catch (\Throwable $e) {
            return ['status' => 'unhealthy', 'error' => $e->getMessage()];
        }
    }
}
