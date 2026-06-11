<?php

use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\MetricsController;
use App\Http\Controllers\Api\NotificationController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);
Route::get('/metrics', MetricsController::class);

Route::prefix('notifications')->group(function () {

    Route::post('/sms', [NotificationController::class, 'sendSms']);
    Route::post('/email', [NotificationController::class, 'sendEmail']);
    Route::post('/push', [NotificationController::class, 'sendPush']);
    Route::post('/bulk', [NotificationController::class, 'bulk']);

    Route::get('/', [NotificationController::class, 'index']);
    Route::get('/{id}', [NotificationController::class, 'show']);

    Route::post('/cancel/batch/{batchId}', [NotificationController::class, 'cancelBatch']);
    Route::post('/cancel/{id}',            [NotificationController::class, 'cancel']);
});
