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
        Schema::create('plex_shows', function (Blueprint $table): void {
            $plex = fn (string $column): string => "_plex_{$column}";

            $table->id();
            $table->foreignId('plex_server_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plex_library_id')->constrained()->cascadeOnDelete();

            $table->string($plex('ratingKey'));
            $table->string($plex('guid'));
            $table->json($plex('guids'))->nullable();
            $table->string($plex('title'));
            $table->integer($plex('year'))->nullable();
            $table->integer($plex('leafCount'))->nullable();
            $table->integer($plex('childCount'))->nullable();
            $table->timestamp($plex('addedAt'))->nullable();
            $table->timestamp($plex('updatedAt'))->nullable();

            $table->string('_imdb_id')->nullable()->index();
            $table->unsignedInteger('_tmdb_id')->nullable()->index();
            $table->unsignedInteger('_tvdb_id')->nullable()->index();

            $table->timestamp('synced_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['plex_server_id', $plex('ratingKey')]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plex_shows');
    }
};
