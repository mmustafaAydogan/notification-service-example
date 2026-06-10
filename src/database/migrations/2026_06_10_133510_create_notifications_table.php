<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('batch_id')->nullable();
            $table->string('idempotency_key', 32)->unique();
            $table->string('channel', 20);
            $table->tinyInteger('priority')->unsigned()->default(5);
            $table->string('status', 20)->default('pending');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->tinyInteger('attempts')->unsigned()->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index('batch_id');
            $table->index(['status', 'priority']);
            $table->index(['channel', 'status']);
            $table->index('scheduled_at');
            $table->index('created_at');
            $table->index('provider_message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
