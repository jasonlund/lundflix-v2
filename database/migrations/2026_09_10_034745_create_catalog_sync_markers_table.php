<?php

declare(strict_types=1);

use App\Domains\Catalog\Support\SyncMarker;
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
        Schema::create('catalog_sync_markers', function (Blueprint $table): void {
            $table->id();

            // One row per feed, so an advance updates the feed's marker rather than
            // appending a second one.
            $table->string('feed')->unique();
            $table->timestamp('marked_at');
            $table->timestamps();
        });

        // The table ships carrying production's live markers, so no feed silently
        // re-syncs a 24h window on deploy.
        resolve(SyncMarker::class)->importFromCache();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('catalog_sync_markers');
    }
};
