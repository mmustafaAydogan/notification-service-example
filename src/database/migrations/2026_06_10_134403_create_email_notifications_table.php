<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('notification_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('recipient', 255);
            $table->string('subject', 255);
            $table->text('body');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_notifications');
    }
};
