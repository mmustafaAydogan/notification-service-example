<?php

namespace App\Console\Commands;

use App\Jobs\ProcessNotificationJob;
use App\Models\ScheduledDispatch;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('notifications:dispatch-due')]
#[Description('Dispatch due (scheduled + retry) notifications from the outbox to the queue.')]
class DispatchDueNotifications extends Command
{
    private const BATCH_SIZE = 500;

    public function handle(): int
    {
        $total = 0;

        do {
            $dispatched = DB::transaction(function () {
                $due = ScheduledDispatch::where('dispatch_at', '<=', now())
                    ->orderBy('dispatch_at')
                    ->limit(self::BATCH_SIZE)
                    ->lockForUpdate()
                    ->with('notification')
                    ->get();

                foreach ($due as $row) {
                    if ($notification = $row->notification) {
                        ProcessNotificationJob::dispatch($notification->id, $notification->priority);
                    }
                    $row->delete();
                }

                return $due->count();
            });

            $total += $dispatched;
        } while ($dispatched === self::BATCH_SIZE);

        $this->info("Dispatched {$total} due notification(s).");

        return self::SUCCESS;
    }
}
