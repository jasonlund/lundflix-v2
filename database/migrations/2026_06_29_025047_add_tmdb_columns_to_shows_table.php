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
            $table->string('_imdb_id')->nullable()->change();
            $table->text('_imdb_primary_title')->nullable()->change();
            $table->string('_imdb_title_type')->nullable()->change();

            $tmdb = fn (string $column): string => "_tmdb_{$column}";

            $table->unsignedInteger($tmdb('id'))->nullable()->unique();
            $table->string($tmdb('imdb_id'))->nullable()->index();
            $table->text($tmdb('name'))->nullable();
            $table->text($tmdb('original_name'))->nullable();
            $table->string($tmdb('original_language'))->nullable();
            $table->text($tmdb('overview'))->nullable();
            $table->text($tmdb('tagline'))->nullable();
            $table->string($tmdb('status'))->nullable();
            $table->date($tmdb('first_air_date'))->nullable();
            $table->float($tmdb('popularity'))->nullable();
            $table->float($tmdb('vote_average'))->nullable();
            $table->unsignedInteger($tmdb('vote_count'))->nullable();
            $table->json($tmdb('genres'))->nullable();
            $table->string($tmdb('poster_path'))->nullable();
            $table->string($tmdb('backdrop_path'))->nullable();
            $table->json($tmdb('external_ids'))->nullable();

            $table->timestamp('tmdb_synced_at')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shows', function (Blueprint $table): void {
            $tmdb = fn (string $column): string => "_tmdb_{$column}";

            $table->dropColumn([
                ...array_map($tmdb, [
                    'id', 'imdb_id', 'name', 'original_name', 'original_language',
                    'overview', 'tagline', 'status', 'first_air_date', 'popularity',
                    'vote_average', 'vote_count', 'genres', 'poster_path',
                    'backdrop_path', 'external_ids',
                ]),
                'tmdb_synced_at',
            ]);

            $table->string('_imdb_id')->nullable(false)->change();
            $table->text('_imdb_primary_title')->nullable(false)->change();
            $table->string('_imdb_title_type')->nullable(false)->change();
        });
    }
};
