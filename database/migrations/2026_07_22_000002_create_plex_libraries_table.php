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
        Schema::create('plex_libraries', function (Blueprint $table): void {
            $plex = fn (string $column): string => "_plex_{$column}";

            $table->id();
            $table->foreignId('plex_server_id')->constrained()->cascadeOnDelete();

            $table->string($plex('key'));
            $table->string($plex('type'));
            $table->string($plex('title'));
            $table->string($plex('uuid'));
            $table->timestamp($plex('updatedAt'))->nullable();

            $table->timestamp('synced_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['plex_server_id', $plex('key')]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plex_libraries');
    }
};
