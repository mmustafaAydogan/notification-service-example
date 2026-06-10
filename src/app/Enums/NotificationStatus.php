<?php

namespace App\Enums;

enum NotificationStatus: string
{
    case Pending    = 'pending';
    case Processing = 'processing';
    case Sent       = 'sent';
    case Failed     = 'failed';
    case Cancelled  = 'cancelled';

    public function isCancellable(): bool
    {
        return in_array($this, [self::Pending, self::Processing]);
    }
}
