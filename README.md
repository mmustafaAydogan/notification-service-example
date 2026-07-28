# Notification System

Event-driven, multi-channel (SMS / Email / Push) notification dispatch service with batch ingestion, idempotent writes, priority queue processing, per-channel rate limiting, and a pluggable external provider abstraction.

---

## Tech Stack

| Layer            | Technology                                       |
|------------------|--------------------------------------------------|
| Framework        | Laravel 13 (PHP 8.3+)                            |
| Database         | MariaDB (LTS)                                    |
| Cache / Idempotency | Redis                                         |
| Message Broker   | RabbitMQ (with native priority queue)            |
| Request Log Store | MongoDB 7 (incoming + outgoing request logs)    |
| External Provider | webhook.site (simulated SMS / Email / Push gateway) |
| API Documentation | Swagger / L5-Swagger                            |
| Container Runtime | Docker Compose                                  |

---

## Quick Start

### Prerequisites
- Docker Engine 24+ and Docker Compose v2

### One-command setup

```bash
./bin/start
```

This single command:
1. Builds images and starts all containers (PHP, Nginx, MariaDB, Redis, RabbitMQ, worker, scheduler)
2. Installs Composer dependencies
3. Runs database migrations
4. Generates the OpenAPI documentation served at `/api/documentation`

The API is now available at **http://localhost:8080**.

### Verify the setup

```bash
curl http://localhost:8080/api/health
```

Expected response:

```json
{
  "status": "healthy",
  "checks": {
    "database": { "status": "healthy" },
    "redis":    { "status": "healthy" },
    "rabbitmq": { "status": "healthy" }
  }
}
```

If any dependency reports `unhealthy`, inspect `docker compose logs` for the failing service.

### Useful endpoints

| URL                                           | Purpose                                        |
|----------------------------------------------|------------------------------------------------|
| `http://localhost:8080/api/health`            | Liveness probe (DB / Redis / RabbitMQ)         |
| `http://localhost:8080/api/metrics`           | Operational metrics                            |
| `http://localhost:8080/api/documentation`     | Swagger UI (OpenAPI spec)                      |
| `http://localhost:15672`                      | RabbitMQ Management UI                         |

### Stop the stack

```bash
./bin/stop
```

### Helper scripts

```bash
./bin/artisan <cmd>     # wraps `docker compose exec php php artisan`
./bin/composer <cmd>    # wraps `docker compose exec php composer`
```

---

## Architecture Overview

```
                       ┌────────────────────────────────────────────────┐
                       │                Laravel API                     │
   HTTP request        │ FormRequest validation                         │
   ─────────────────▶  │ → NotificationService / BulkNotificationService│
                       │ → Idempotency check (Redis)                    │
                       │ → DB insert (notifications + channel detail)   │
                       │ → Dispatch ProcessNotificationJob (id only)    │
                       └────────────────┬───────────────────────────────┘
                                        │
                                        ▼
                       ┌────────────────────────────────────────────────┐
                       │              RabbitMQ                          │
                       │  Single queue `notifications`                  │
                       │  Native priority (max_priority: 10)            │
                       │  high=10  normal=5  low=1                      │
                       └────────────────┬───────────────────────────────┘
                                        │
                                        ▼
                       ┌────────────────────────────────────────────────┐
                       │            Queue Worker                        │
                       │  1. Re-fetch notification + channel detail     │
                       │  2. Skip if not in `pending` state             │
                       │  3. Per-channel rate-limit gate (100 / sec)    │
                       │  4. Atomic transition → `processing`           │
                       │  5. Build payload via ChannelHandler           │
                       │  6. POST to webhook.site                       │
                       │  7. → `sent` / `failed` / retry (status reset) │
                       └────────────────────────────────────────────────┘

      ┌─────────────────┐  ┌───────────────────┐  ┌────────────────────┐
      │ Redis           │  │ MariaDB           │  │ webhook.site       │
      │ - Idempotency   │  │ - Source of truth │  │ - External provider│
      │ - Rate limiter  │  │ - notifications   │  │   (simulated)      │
      │                 │  │ - sms / email /   │  │                    │
      │                 │  │   push details    │  │                    │
      └─────────────────┘  └───────────────────┘  └────────────────────┘

      ┌──────────────────────────────────────────────────────────────┐
      │ MongoDB                                                      │
      │ - incoming_requests : every API call (request + response)    │
      │ - outgoing_requests : every webhook.site call (req + resp)   │
      │ - joined by `notification_id` / `batch_id`                   │
      └──────────────────────────────────────────────────────────────┘

      ┌──────────────────────────────────────────────────────────────┐
      │ Scheduler (per minute)  ->  notifications:dispatch-due       │
      │ - reads outbox rows that are ready to send                   │
      │ - re-queues scheduled + retry notifications to RabbitMQ      │
      └──────────────────────────────────────────────────────────────┘
```

### Key design decisions

**Hybrid queue model.** MariaDB is the source of truth for notification state (status, attempts, scheduled_at). RabbitMQ is the transport layer. The job message carries only the notification UUID; the worker re-reads the canonical record from MariaDB on every pickup. This keeps state inspectable, cancel operations consistent, and message bodies tiny.

**Channel detail tables.** Each channel has its own table (`sms_notifications`, `email_notifications`, `push_notifications`) referenced by `notification_id`. The base `notifications` table holds only cross-channel state (status, priority, batch_id, idempotency_key, attempts, timestamps). This keeps indexes lean and allows per-channel evolution.

**Strategy pattern for channels.** A `ChannelHandler` contract (`validationRules`, `idempotencyHash`, `persistDetail`, `persistDetailsBatch`, `payloadFromNotification`) is implemented for SMS, Email, and Push. Handlers are tagged in the service container, gathered into a `ChannelHandlerRegistry`, and resolved by channel enum at request and worker time. Adding a new channel is one class + one tag.

**Idempotency.** A deterministic hash `md5(recipient | content | channel)` per channel acts as the idempotency key. The hash is stored both in Redis (24h TTL, hot path) and as a `UNIQUE` constraint on the `notifications` table (durable guarantee). Duplicate ingestion returns HTTP 409 with the existing notification ID.

**Per-channel rate limiting.** Each channel has its own Laravel `RateLimiter` bucket allowing 100 requests per second. When the worker hits the limit, the job re-dispatches itself with a short delay; the message is acked, attempts are not incremented.

**Priority queue.** A single RabbitMQ queue with `queue_max_priority: 10` and `prioritize_delayed: true`. Higher priority messages are delivered first; a single consumer drains them in order.

**Atomic state transitions.** State changes that may race with concurrent operations (`Pending → Processing`, `* → Cancelled`) are implemented as conditional `UPDATE … WHERE status = …` statements with affected-row inspection. This eliminates read-then-write races between the HTTP cancel path and the worker's pickup path.

**Retry strategy.** On transient provider errors the worker increments `attempts`, captures `last_error`, resets the notification to `pending`, and adds a `scheduled_dispatches` (outbox) row to be re-sent `15 minutes` later. The `notifications:dispatch-due` scheduler re-queues it when its time comes. After 5 failed attempts the notification is marked `failed`. HTTP 422 from the provider (semantic validation failure) is terminal and does not retry.

**Scheduled delivery (transactional outbox).** A request may carry a `scheduled_at` (`Y-m-d H:i`, future). Instead of going straight to the queue, such notifications get a row in the `scheduled_dispatches` outbox table, written in the same transaction as the notification. A per-minute scheduler (`notifications:dispatch-due`, run by the `scheduler` container) reads the rows that are ready to send and dispatches them to RabbitMQ. The same outbox is reused for retries, so scheduled sends and retries follow one path. Immediate notifications skip the outbox and go straight to RabbitMQ, keeping the hot path fast.

---

## API Reference

All endpoints are prefixed with `/api`. Successful ingestion uses HTTP 202 (Accepted) because delivery is asynchronous.

The full OpenAPI specification is browsable at **http://localhost:8080/api/documentation**.

Common error shapes:

```jsonc
// HTTP 422 — validation error
{ "message": "The given data was invalid.", "errors": { "field": ["..."] } }

// HTTP 409 — duplicate (idempotency hit)
{ "message": "Notification already exists with this idempotency key",
  "existing_notification_id": "..." }
```

### Send an SMS notification

```bash
curl -X POST http://localhost:8080/api/notifications/sms \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "recipient": "+905551234567",
    "content":   "Your order #12345 is on its way",
    "priority":  "High"
  }'
```

**202 Accepted**
```json
{
  "id": "0a3b2f9c-2bb0-4d7c-8f0f-7c1d5e2c1f0a",
  "status": "pending",
  "created_at": "2026-06-12T14:00:00.000000Z"
}
```

Constraints: `recipient` must be in E.164 format (`+[country code][number]`), `content` ≤ 160 characters. `priority` is optional (one of `Low | Medium | High`).

To deliver later, add `scheduled_at` (`Y-m-d H:i`, must be in the future). Available on every channel and on bulk items:

```bash
curl -X POST http://localhost:8080/api/notifications/sms \
  -H "Content-Type: application/json" \
  -d '{
    "recipient":    "+905551234567",
    "content":      "Reminder: your appointment is tomorrow",
    "scheduled_at": "2026-07-01 09:00"
  }'
```

The notification stays `pending` until the scheduler sends it at `scheduled_at` (minute granularity); it can still be cancelled before then.

### Send an Email notification

```bash
curl -X POST http://localhost:8080/api/notifications/email \
  -H "Content-Type: application/json" \
  -d '{
    "recipient": "customer@example.com",
    "subject":   "Order confirmed",
    "body":      "Hello, your order has been confirmed.",
    "priority":  "Medium"
  }'
```

Constraints: `subject` ≤ 255, `body` ≤ 10 000 characters.

### Send a Push notification

```bash
curl -X POST http://localhost:8080/api/notifications/push \
  -H "Content-Type: application/json" \
  -d '{
    "device_token": "fcm-token-abc123-xyz789",
    "title":        "Your order is on its way",
    "body":         "Tap to track the shipment.",
    "priority":     "Low"
  }'
```

Constraints: `device_token` ≥ 10 characters, `title` ≤ 255, `body` ≤ 256.

### Send a mixed-channel batch

```bash
curl -X POST http://localhost:8080/api/notifications/bulk \
  -H "Content-Type: application/json" \
  -d '{
    "notifications": [
      { "channel": "sms",   "recipient": "+905551234567", "content": "Item A shipped", "priority": "High" },
      { "channel": "email", "recipient": "a@example.com", "subject": "Receipt", "body": "Thanks!" },
      { "channel": "push",  "device_token": "fcm-token-xyz", "title": "Hi", "body": "Welcome" }
    ]
  }'
```

**202 Accepted**
```json
{
  "batch_id": "f4f9c1b2-...-...",
  "accepted": 2,
  "rejected": 1,
  "errors":   [
    { "index": 2, "reason": "The body field is required." }
  ]
}
```

Up to 1000 items per request. Each item is validated, deduplicated against existing idempotency keys (Redis), then inserted and dispatched. Per-item rejection does not abort accepted items in the same batch.

### List notifications

```bash
# Filterable, paginated
curl "http://localhost:8080/api/notifications?status=sent&channel=sms&per_page=50&page=1"

# All notifications in a batch
curl "http://localhost:8080/api/notifications?batch_id=f4f9c1b2-...&per_page=100"

# Date range
curl "http://localhost:8080/api/notifications?from=2026-06-01&to=2026-06-12"
```

Query parameters:

| Parameter   | Type    | Notes                                                |
|-------------|---------|------------------------------------------------------|
| `status`    | string  | `pending`, `processing`, `sent`, `failed`, `cancelled` |
| `channel`   | string  | `sms`, `email`, `push`                               |
| `batch_id`  | UUID    | Only notifications belonging to this batch          |
| `from`      | date    | `created_at >= from` (`YYYY-MM-DD`)                  |
| `to`        | date    | `created_at <= to` (`YYYY-MM-DD`)                    |
| `per_page`  | integer | 1–100, default 20                                    |
| `page`      | integer | 1-based                                              |

Response is a paginated collection: `{ "data": [...summary...], "meta": { current_page, last_page, per_page, total, ... } }`.

### Get a single notification

```bash
curl "http://localhost:8080/api/notifications/0a3b2f9c-2bb0-4d7c-8f0f-7c1d5e2c1f0a"
```

Response includes a `detail` block whose shape depends on `channel`:

```json
{
  "id":                  "0a3b2f9c-...",
  "channel":             "sms",
  "priority":            "High",
  "status":              "sent",
  "batch_id":            null,
  "provider_message_id": "wh-msg-abc123",
  "attempts":            0,
  "scheduled_at":        null,
  "sent_at":             "2026-06-12T14:00:03+00:00",
  "created_at":          "2026-06-12T14:00:00+00:00",
  "detail": {
    "recipient": "+905551234567",
    "content":   "Your order #12345 is on its way"
  }
}
```

### Cancel a single notification

```bash
curl -X POST http://localhost:8080/api/notifications/cancel/0a3b2f9c-...
```

**200 OK**
```json
{ "id": "0a3b2f9c-...", "status": "cancelled", "updated_at": "2026-06-12T14:00:00+00:00" }
```

Cancellation only applies while the notification is `pending`. Once the worker has picked the message up (`processing`) or it has reached a terminal state (`sent` / `failed` / `cancelled`), the endpoint returns **409 Conflict**:

```json
{ "message": "Notification with status 'sent' cannot be cancelled." }
```

### Cancel an entire batch

```bash
curl -X POST http://localhost:8080/api/notifications/cancel/batch/f4f9c1b2-...
```

**200 OK**
```json
{ "cancelled": ["uuid-1", "uuid-2", "uuid-3"] }
```

Only `pending` members of the batch are cancelled. The operation is atomic: a transaction acquires row locks, plucks cancellable IDs, and performs a single `UPDATE` — no race with concurrent worker pickups.

### Health check

```bash
curl http://localhost:8080/api/health
```

Returns HTTP 200 when all dependencies (MariaDB, Redis, RabbitMQ) are reachable, or HTTP 503 with per-component status otherwise.

```json
{
  "status": "healthy",
  "checks": {
    "database": { "status": "healthy" },
    "redis":    { "status": "healthy" },
    "rabbitmq": { "status": "healthy" }
  }
}
```

### Metrics

```bash
curl http://localhost:8080/api/metrics
```

Returns aggregate counters and operational telemetry suitable for scraping or dashboarding.

```json
{
  "notifications": {
    "total":      1024,
    "by_status":  { "pending": 12, "processing": 4, "sent": 990, "failed": 16, "cancelled": 2 },
    "by_channel": { "sms": 500, "email": 400, "push": 124 }
  },
  "rates":   { "success_rate": 96.68, "failure_rate": 1.56 },
  "queue":   { "driver": "rabbitmq", "name": "notifications", "depth": 12 },
  "latency": { "avg_seconds": 3.21, "sample_size": 990 }
}
```

`queue.depth` is read live from the RabbitMQ management API. `latency.avg_seconds` is the average end-to-end delay between `created_at` and `sent_at` for the `sent` population.

---

## Request Logging

Every API request and every webhook call is mirrored into MongoDB for after-the-fact inspection and debugging. Two separate collections live under the `notification_logs` database.

### `incoming_requests`

Written by the `LogRequests` middleware in the `terminate()` phase, so the user receives the HTTP response first and the log is persisted asynchronously after.

| Field             | Notes                                                       |
|-------------------|-------------------------------------------------------------|
| `trace_id`        | `X-Request-Id` header (echoed back on the response), or a generated UUID |
| `notification_id` | Extracted from response JSON (`id` / `existing_notification_id`) |
| `batch_id`        | Extracted from response JSON (`batch_id`)                   |
| `method`, `path`, `query` | HTTP request line                                   |
| `headers`         | Sensitive headers (`Authorization`, `Cookie`, `X-Api-Key`, `X-Auth-Token`) redacted |
| `request_body`    | Parsed JSON when possible, raw otherwise                    |
| `status_code`     | HTTP status returned to the client                          |
| `response_body`   | Same shape as `request_body`                                |
| `latency_ms`      | Wall-clock time between request and response                |
| `ip`, `user_agent` | Client identity signals                                    |
| `logged_at`       | Timestamp                                                   |

### `outgoing_requests`

Written by `WebhookProvider` around the `Http::post()` call. The write happens in a `finally` block, so success, 4xx, 5xx, and timeout all produce a log entry.

| Field             | Notes                                                       |
|-------------------|-------------------------------------------------------------|
| `notification_id` | Passed in by `ProcessNotificationJob`                       |
| `channel`         | `sms` / `email` / `push`                                    |
| `method`, `url`   | Outbound HTTP request line                                  |
| `request_body`    | Payload sent to the provider                                |
| `status_code`     | HTTP status returned by the provider; `null` on transport failure |
| `response_body`   | Parsed JSON when possible, raw otherwise                    |
| `latency_ms`      | Wall-clock time around the HTTP call                        |
| `error`           | Exception message when the call failed; `null` on success   |
| `logged_at`       | Timestamp                                                   |

Both writes are fire-and-forget — a failed log entry is captured in the application log via `logger()->warning(...)` and does not affect the business flow.

The two collections share `notification_id`, so a single notification can be traced end-to-end:

```js
// In mongosh, against the notification_logs DB
const nid = "0a3b2f9c-...";
db.incoming_requests.find({ notification_id: nid }).pretty();
db.outgoing_requests.find({ notification_id: nid }).pretty();
```

For bulk endpoints, the same correlation works via `batch_id`.

---

## Testing

The full test suite runs against the dockerized PHP container with a single command:

```bash
./bin/test
```

The script auto-starts the `php` service if it is not already running, installs Composer dependencies if needed, then executes `php artisan test`.

### Coverage scope

| Suite | Count | What it locks down |
|------|------|--------------------|
| **Unit** | 17 | `PriorityStatus` / `NotificationStatus` enums, SMS / Email / Push channel handlers (`idempotencyHash`, `validationRules`, channel routing), `ChannelHandlerRegistry` (resolution, missing-handler error, common-rule merge). |
| **Feature — service layer** | 9 | `NotificationService::send` happy path (DB write + detail row), default-priority fallback, `ProcessNotificationJob` dispatch with the resolved priority, Redis idempotency key write-through, duplicate-key path raising `DuplicateNotificationException`, scheduled send writing an outbox row without dispatching, and immediate send skipping the outbox. |
| **Feature — API** | 17 | Every endpoint under `/api/notifications`: HTTP 202 on each channel + persistence, HTTP 409 on duplicate, HTTP 422 on validation failure, pagination + `status` / `channel` filters, `show` 200/404, `cancel` 200/409/404 + outbox cleanup, `cancel/batch` filtering only `pending` members, `bulk` partial-success accounting, and per-item priority propagation in bulk dispatch. |
| **Feature — scheduler** | 3 | `notifications:dispatch-due` sends due `scheduled_dispatches` rows to the queue, leaves future rows untouched, and propagates each notification's priority. |

### Test environment

Tests run on an isolated configuration declared in `src/phpunit.xml`:

- `DB_CONNECTION=sqlite` with `DB_DATABASE=:memory:` — each test class restarts on a freshly migrated DB via `RefreshDatabase`
- `QUEUE_CONNECTION=sync` plus `Queue::fake()` in feature tests — `ProcessNotificationJob` dispatches are captured and asserted, not executed (no real HTTP to webhook.site, no MongoDB writes from the worker path)
- `REDIS_DB=15` with prefix `noti-test:` — idempotency keys live in a dedicated Redis logical DB; `TestCase::setUp` flushes it at the start of every test
- `LogRequests` middleware disabled per-test via `withoutMiddleware` — MongoDB stays out of the loop during HTTP tests

No additional setup is required beyond `./bin/test`.

---

## Configuration

The application is configured exclusively through environment variables. The most relevant ones:

| Variable                          | Purpose                                                       |
|-----------------------------------|---------------------------------------------------------------|
| `APP_KEY`                         | Laravel encryption key (set with `./bin/artisan key:generate`)|
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | MariaDB connection           |
| `REDIS_HOST`, `REDIS_PORT`        | Redis connection (idempotency + rate limiter)                 |
| `QUEUE_CONNECTION`                | Must be `rabbitmq`                                            |
| `RABBITMQ_HOST`, `RABBITMQ_PORT`  | AMQP endpoint                                                 |
| `RABBITMQ_LOGIN`, `RABBITMQ_PASSWORD`, `RABBITMQ_VHOST` | RabbitMQ credentials                       |
| `RABBITMQ_QUEUE`                  | Queue name (default `notifications`)                          |
| `RABBITMQ_MANAGEMENT_HOST`, `RABBITMQ_MANAGEMENT_PORT` | Management API endpoint used by `/api/metrics` |
| `MONGO_HOST`, `MONGO_PORT`        | MongoDB connection used by the request log store              |
| `MONGO_DATABASE`                  | Database holding `incoming_requests` and `outgoing_requests`  |
| `MONGO_USERNAME`, `MONGO_PASSWORD`, `MONGO_AUTH_SOURCE` | MongoDB credentials (auth DB defaults to `admin`) |
| `WEBHOOK_SITE_URL`                | Target URL for the external provider simulation              |

`docker-compose.yml` exposes the dependent services on the host network as well:

| Service     | Host port | Notes                                    |
|-------------|-----------|------------------------------------------|
| nginx       | 8080      | API entrypoint                           |
| MariaDB     | 3306      | `notification_user / notification_pass`  |
| Redis       | 6379      | No auth (dev only)                       |
| RabbitMQ    | 5672      | AMQP                                     |
| RabbitMQ UI | 15672     | Management console                       |
| MongoDB     | 27017     | `notification_user / notification_pass`, auth DB `admin` |

---

## Project Layout

```
.
├── bin/                       # Convenience scripts (start / stop / artisan / composer)
├── docker-compose.yml         # Service topology
├── images/                    # Custom PHP / nginx images
└── src/                       # Laravel application
    ├── app/
    │   ├── Contracts/                          # NotificationProvider, ProviderResponse
    │   ├── Enums/                              # NotificationChannel, NotificationStatus, PriorityStatus
    │   ├── Exceptions/                         # DuplicateNotificationException (HTTP 409)
    │   ├── Http/
    │   │   ├── Controllers/Api/                # Notification / Health / Metrics
    │   │   ├── Middleware/                     # LogRequests (incoming request → MongoDB)
    │   │   ├── Requests/Api/                   # FormRequest validators (one per channel + bulk)
    │   │   └── Resources/                      # Notification / NotificationCollection
    │   ├── Jobs/                               # ProcessNotificationJob (id-only payload)
    │   ├── Models/                             # Notification + channel detail models + IncomingRequestLog / OutgoingRequestLog
    │   ├── Providers/                          # AppServiceProvider (handler tagging, rate limiters)
    │   └── Services/
    │       ├── NotificationService.php         # Single-channel ingestion
    │       ├── BulkNotificationService.php     # Batched ingestion
    │       ├── WebhookProvider.php             # webhook.site external provider
    │       └── Notification/Channels/          # Strategy pattern: ChannelHandler + registry
    ├── config/                                 # queue.php (RabbitMQ + priority), services.php, ...
    ├── database/migrations/                    # notifications + channel detail tables
    └── routes/api.php                          # All HTTP routes
```

---

## Database Schema

### `notifications`

The cross-channel state record.

| Column                | Type / Constraints                          |
|-----------------------|---------------------------------------------|
| `id`                  | UUID, PK                                    |
| `batch_id`            | UUID, nullable, indexed                     |
| `idempotency_key`     | char(32), UNIQUE                            |
| `channel`             | string (`sms` / `email` / `push`)           |
| `priority`            | tinyint (`1` low / `5` normal / `10` high) |
| `status`              | string (`pending` / `processing` / `sent` / `failed` / `cancelled`) |
| `scheduled_at`        | timestamp, nullable (next retry due time)   |
| `sent_at`             | timestamp, nullable                         |
| `provider_message_id` | string, nullable, indexed                   |
| `attempts`            | tinyint                                     |
| `last_error`          | text, nullable                              |
| `created_at` / `updated_at` | timestamps                            |

Indexes: `(status, priority)`, `(channel, status)`, `scheduled_at`, `created_at`, `batch_id`, `provider_message_id`.

### `sms_notifications`, `email_notifications`, `push_notifications`

Channel-specific payloads. Each has a unique foreign key to `notifications.id` and cascade-deletes with its parent.

| Table                 | Columns                                            |
|-----------------------|----------------------------------------------------|
| `sms_notifications`   | `recipient` (E.164), `content` (≤ 160)            |
| `email_notifications` | `recipient`, `subject` (≤ 255), `body`            |
| `push_notifications`  | `device_token`, `title` (≤ 255), `body`           |

