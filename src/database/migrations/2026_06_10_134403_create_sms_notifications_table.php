<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('notification_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('recipient', 20);
            $table->string('content', 160);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_notifications');
    }
};
