<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('notification_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('device_token');
            $table->string('title', 255);
            $table->text('body');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_notifications');
    }
};
