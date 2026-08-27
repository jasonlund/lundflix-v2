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
            // Not redundant with the unique _tmdb_id index: the sync probe filters
            // on _tmdb_id and tmdb_synced_at and selects only _tmdb_id, so this
            // composite answers it from the index alone. The single-column index
            // matches the same rows but still reads each one for tmdb_synced_at.
            $table->index(['_tmdb_id', 'tmdb_synced_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('movies', function (Blueprint $table): void {
            $table->dropIndex(['_tmdb_id', 'tmdb_synced_at']);
        });
    }
};
