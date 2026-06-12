<?php

namespace App\Http\Middleware;

use App\Models\IncomingRequestLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class LogRequests
{
    private const REDACTED_HEADERS = [
        'authorization',
        'cookie',
        'x-api-key',
        'x-auth-token',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $traceId = $request->header('X-Request-Id') ?: (string) Str::uuid();

        $request->attributes->set('_log_started_at', microtime(true));
        $request->attributes->set('_log_trace_id', $traceId);
        $request->headers->set('X-Request-Id', $traceId);

        $response = $next($request);

        $response->headers->set('X-Request-Id', $traceId);

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            $startedAt = (float) $request->attributes->get('_log_started_at', microtime(true));
            $traceId = (string) $request->attributes->get('_log_trace_id', '');

            $capturedResponse = $this->capture($response->getContent() ?: '');
            [$notificationId, $batchId] = $this->extractIdentifiers($capturedResponse);

            IncomingRequestLog::create([
                'trace_id' => $traceId,
                'notification_id' => $notificationId,
                'batch_id' => $batchId,
                'method' => $request->getMethod(),
                'path' => $request->path(),
                'query' => $request->query(),
                'headers' => $this->sanitizeHeaders($request->headers->all()),
                'request_body' => $this->capture($request->getContent()),
                'status_code' => $response->getStatusCode(),
                'response_body' => $capturedResponse,
                'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'logged_at' => now(),
            ]);
        } catch (Throwable $e) {
            logger()->warning('request_log_write_failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function extractIdentifiers(array $capturedResponse): array
    {
        $json = $capturedResponse['json'] ?? null;
        if (! is_array($json)) {
            return [null, null];
        }

        $notificationId = $json['id'] ?? $json['existing_notification_id'] ?? null;
        $batchId = $json['batch_id'] ?? null;

        return [$notificationId, $batchId];
    }

    private function sanitizeHeaders(array $headers): array
    {
        foreach ($headers as $name => $_value) {
            if (in_array(strtolower($name), self::REDACTED_HEADERS, true)) {
                $headers[$name] = ['[redacted]'];
            }
        }

        return $headers;
    }

    private function capture(string $body): array
    {
        if ($body === '') {
            return ['empty' => true];
        }

        $decoded = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return ['json' => $decoded];
        }

        return ['raw' => $body];
    }
}
