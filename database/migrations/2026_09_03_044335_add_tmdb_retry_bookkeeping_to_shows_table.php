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
        Schema::table('shows', function (Blueprint $table): void {
            // App-owned bookkeeping, so unprefixed — TMDB reports neither. Placed
            // straight after the stamp they qualify: a row is a hydrate candidate
            // while tmdb_synced_at is null, and these two say when to try again.
            $table->after('tmdb_synced_at', function (Blueprint $table): void {
                $table->unsignedInteger('tmdb_unresolved_attempts')->default(0);
                $table->timestamp('tmdb_retry_after')->nullable();
            });

            // The hydrate walk's own filter, which the (_tmdb_id, tmdb_synced_at)
            // probe index cannot serve: that one leads on _tmdb_id, and the rows
            // this walk is heaviest over carry none.
            $table->index(['tmdb_synced_at', 'tmdb_retry_after']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shows', function (Blueprint $table): void {
            $table->dropIndex(['tmdb_synced_at', 'tmdb_retry_after']);
            $table->dropColumn(['tmdb_unresolved_attempts', 'tmdb_retry_after']);
        });
    }
};
