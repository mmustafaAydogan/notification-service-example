<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledDispatch extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'notification_id',
        'dispatch_at',
    ];

    protected $casts = [
        'dispatch_at' => 'datetime',
        'created_at'  => 'datetime',
    ];

    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }
}
