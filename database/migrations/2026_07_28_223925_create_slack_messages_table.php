<?php

declare(strict_types=1);

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
        Schema::create('slack_messages', function (Blueprint $table): void {
            $table->id();
            $table->string('channel');
            $table->string('message_ts');
            $table->string('type');
            $table->text('content');
            $table->timestamp('sent_at');
            $table->timestamps();

            // Slack identifies a message by (channel, ts), so a re-delivery of the
            // same message updates the log row rather than duplicating it.
            $table->unique(['channel', 'message_ts']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('slack_messages');
    }
};
