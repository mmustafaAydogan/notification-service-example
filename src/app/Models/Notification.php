<?php

namespace App\Models;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Notification extends Model
{
    use HasUuids;

    protected $fillable = [
        'batch_id',
        'idempotency_key',
        'channel',
        'priority',
        'status',
        'scheduled_at',
        'sent_at',
        'provider_message_id',
        'last_error',
        'attempts',
    ];

    protected $casts = [
        'channel'      => NotificationChannel::class,
        'status'       => NotificationStatus::class,
        'scheduled_at' => 'datetime',
        'sent_at'      => 'datetime',
        'priority'     => 'integer',
        'attempts'     => 'integer',
    ];

    public function markAsProcessing(): void
    {
        $this->update(['status' => NotificationStatus::Processing]);
    }

    public function markAsSent(string $providerMessageId): void
    {
        $this->update([
            'status'              => NotificationStatus::Sent,
            'sent_at'             => now(),
            'provider_message_id' => $providerMessageId,
        ]);
    }

    public function markAsFailed(string $reason): void
    {
        $this->update([
            'status'     => NotificationStatus::Failed,
            'last_error' => $reason,
        ]);
    }

    public function recordError(string $reason): void
    {
        $this->increment('attempts');
        $this->update(['last_error' => $reason]);
    }

    public function smsNotification(): HasOne
    {
        return $this->hasOne(SmsNotification::class);
    }

    public function emailNotification(): HasOne
    {
        return $this->hasOne(EmailNotification::class);
    }

    public function pushNotification(): HasOne
    {
        return $this->hasOne(PushNotification::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', NotificationStatus::Pending);
    }

    public function scopeCancellable(Builder $query): Builder
    {
        return $query->whereIn('status', [NotificationStatus::Pending, NotificationStatus::Processing]);
    }

    public function scopeInBatch(Builder $query, string $batchId): Builder
    {
        return $query->where('batch_id', $batchId);
    }

    public function scopeForChannel(Builder $query, NotificationChannel $channel): Builder
    {
        return $query->where('channel', $channel);
    }
}
