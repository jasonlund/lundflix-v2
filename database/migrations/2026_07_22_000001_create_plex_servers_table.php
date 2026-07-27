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
        Schema::create('plex_servers', function (Blueprint $table): void {
            $plex = fn (string $column): string => "_plex_{$column}";

            $table->id();

            $table->string($plex('clientIdentifier'))->unique();
            $table->string($plex('name'));
            $table->string('uri');

            $table->timestamp('synced_at')->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plex_servers');
    }
};
