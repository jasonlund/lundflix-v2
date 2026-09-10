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
        Schema::table('movies', function (Blueprint $table): void {
            // App-owned bookkeeping, so unprefixed: TMDB reports no such attribute —
            // it is our record of what our own last fetch found. `shows` gets no
            // counterpart; a show TMDB answers nothing for is deferred instead
            // (tmdb_unresolved_attempts / tmdb_retry_after, ADR-0005).
            $table->timestamp('tmdb_gone_at')->nullable()->after('tmdb_synced_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('movies', function (Blueprint $table): void {
            $table->dropColumn('tmdb_gone_at');
        });
    }
};
