<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('scheduled_dispatches', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('notification_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamp('dispatch_at');
            $table->timestamp('created_at')->nullable()->useCurrent();

            $table->index('dispatch_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scheduled_dispatches');
    }
};
