<?php

namespace App\Services;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\PriorityStatus;
use App\Jobs\ProcessNotificationJob;
use App\Models\Notification;
use App\Models\ScheduledDispatch;
use App\Services\Notification\Channels\ChannelHandlerRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class BulkNotificationService
{
    public function __construct(
        private ChannelHandlerRegistry $registry,
    ) {}

    public function send(array $notifications): array
    {
        $batchId = Str::uuid()->toString();
        $errors  = [];

        $valid = $this->validateAll($notifications, $batchId, $errors);

        if (empty($valid)) {
            return $this->respond($batchId, 0, $errors);
        }

        $toInsert = $this->filterDuplicates($valid, $errors);

        if (empty($toInsert)) {
            return $this->respond($batchId, 0, $errors);
        }

        $inserted = $this->bulkInsert($toInsert, $batchId);

        $insertedIds = array_flip(array_map(fn ($v) => $v['notification_id'], $inserted));
        foreach ($toInsert as $v) {
            if (!isset($insertedIds[$v['notification_id']])) {
                $errors[] = ['index' => $v['index'], 'reason' => 'duplicate'];
            }
        }

        $this->storeIdempotencyKeys($inserted);
        $this->dispatchImmediate($inserted);

        return $this->respond($batchId, count($inserted), $errors);
    }

    private function validateAll(array $notifications, string $batchId, array &$errors): array
    {
        $valid = [];

        foreach ($notifications as $index => $item) {
            $item['batch_id'] = $batchId;

            $validation = $this->validate($item);

            if ($validation->fails()) {
                $errors[] = ['index' => $index, 'reason' => $validation->errors()->first()];
                continue;
            }

            $channel = NotificationChannel::from($item['channel']);
            $handler = $this->registry->handlerFor($channel);

            $valid[] = [
                'index'           => $index,
                'data'            => $item,
                'channel'         => $channel,
                'idempotency_key' => $handler->idempotencyHash($item),
            ];
        }

        return $valid;
    }

    private function filterDuplicates(array $valid, array &$errors): array
    {
        $keys     = array_map(fn ($v) => "idempotency:{$v['idempotency_key']}", $valid);
        $existing = Redis::mget($keys);

        $toInsert = [];
        $seen     = [];

        foreach ($valid as $i => $v) {
            $key = $v['idempotency_key'];

            if (!empty($existing[$i]) || isset($seen[$key])) {
                $errors[] = ['index' => $v['index'], 'reason' => 'duplicate'];
                continue;
            }

            $seen[$key]            = true;
            $v['notification_id']  = Str::uuid()->toString();
            $toInsert[]            = $v;
        }

        return $toInsert;
    }

    private function bulkInsert(array $toInsert, string $batchId): array
    {
        $now = now();

        $baseRows = array_map(fn ($v) => [
            'id'              => $v['notification_id'],
            'batch_id'        => $batchId,
            'idempotency_key' => $v['idempotency_key'],
            'channel'         => $v['channel']->value,
            'priority'        => $this->resolvePriority($v['data']),
            'status'          => NotificationStatus::Pending->value,
            'scheduled_at'    => $v['data']['scheduled_at'] ?? null,
            'attempts'        => 0,
            'created_at'      => $now,
            'updated_at'      => $now,
        ], $toInsert);

        return DB::transaction(function () use ($toInsert, $baseRows, $now) {
            Notification::insertOrIgnore($baseRows);

            $ids         = array_map(fn ($v) => $v['notification_id'], $toInsert);
            $insertedIds = array_flip(Notification::whereIn('id', $ids)->pluck('id')->all());

            $inserted = array_values(array_filter(
                $toInsert,
                fn ($v) => isset($insertedIds[$v['notification_id']]),
            ));

            if (empty($inserted)) {
                return [];
            }

            $byChannel = [];
            foreach ($inserted as $v) {
                $byChannel[$v['channel']->value][] = [
                    'notification_id' => $v['notification_id'],
                    'data'            => $v['data'],
                ];
            }

            foreach ($byChannel as $channelValue => $items) {
                $this->registry
                    ->handlerFor(NotificationChannel::from($channelValue))
                    ->persistDetailsBatch($items);
            }

            $dispatchRows = [];
            foreach ($inserted as $v) {
                if (($v['data']['scheduled_at'] ?? null) !== null) {
                    $dispatchRows[] = [
                        'notification_id' => $v['notification_id'],
                        'dispatch_at'     => $v['data']['scheduled_at'],
                        'created_at'      => $now,
                    ];
                }
            }

            if (!empty($dispatchRows)) {
                ScheduledDispatch::insert($dispatchRows);
            }

            return $inserted;
        });
    }

    private function storeIdempotencyKeys(array $toInsert): void
    {
        Redis::pipeline(function ($pipe) use ($toInsert) {
            foreach ($toInsert as $v) {
                $pipe->setex(
                    "idempotency:{$v['idempotency_key']}",
                    86400,
                    $v['notification_id'],
                );
            }
        });
    }

    private function dispatchImmediate(array $toInsert): void
    {
        foreach ($toInsert as $v) {
            if (($v['data']['scheduled_at'] ?? null) !== null) {
                continue;
            }

            dispatch(new ProcessNotificationJob(
                notificationId: $v['notification_id'],
                priority:       $this->resolvePriority($v['data']),
            ));
        }
    }

    private function validate(array $item): \Illuminate\Validation\Validator
    {
        $rules = ['channel' => ['required', Rule::enum(NotificationChannel::class)]];

        if ($channel = NotificationChannel::tryFrom($item['channel'] ?? '')) {
            $rules += $this->registry->rulesFor($channel);
        }

        return Validator::make($item, $rules);
    }

    private function resolvePriority(array $data): int
    {
        return (PriorityStatus::tryFrom($data['priority'] ?? '') ?? PriorityStatus::Low)->valueInt();
    }

    private function respond(string $batchId, int $accepted, array $errors): array
    {
        return [
            'batch_id' => $batchId,
            'accepted' => $accepted,
            'rejected' => count($errors),
            'errors'   => $errors,
        ];
    }
}
