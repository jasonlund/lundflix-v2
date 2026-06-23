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
            $table->string('imdb_id')->nullable()->change();
            $table->text('title')->nullable()->change();
            $table->string('title_type')->nullable()->change();

            $tmdb = fn (string $column): string => "_tmdb_{$column}";

            $table->unsignedInteger($tmdb('id'))->nullable()->unique();
            $table->string($tmdb('imdb_id'))->nullable()->index();
            $table->text($tmdb('title'))->nullable();
            $table->text($tmdb('original_title'))->nullable();
            $table->string($tmdb('original_language'))->nullable();
            $table->text($tmdb('overview'))->nullable();
            $table->text($tmdb('tagline'))->nullable();
            $table->string($tmdb('homepage'))->nullable();
            $table->string($tmdb('status'))->nullable();
            $table->date($tmdb('release_date'))->nullable();
            $table->unsignedInteger($tmdb('runtime'))->nullable();
            $table->unsignedBigInteger($tmdb('budget'))->nullable();
            $table->unsignedBigInteger($tmdb('revenue'))->nullable();
            $table->float($tmdb('popularity'))->nullable();
            $table->float($tmdb('vote_average'))->nullable();
            $table->unsignedInteger($tmdb('vote_count'))->nullable();
            $table->boolean($tmdb('video'))->nullable();
            $table->json($tmdb('genres'))->nullable();
            $table->json($tmdb('origin_country'))->nullable();
            $table->json($tmdb('production_companies'))->nullable();
            $table->json($tmdb('production_countries'))->nullable();
            $table->json($tmdb('spoken_languages'))->nullable();
            $table->json($tmdb('belongs_to_collection'))->nullable();
            $table->json($tmdb('release_dates'))->nullable();
            $table->string($tmdb('poster_path'))->nullable();
            $table->string($tmdb('backdrop_path'))->nullable();

            $table->timestamp('tmdb_synced_at')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('movies', function (Blueprint $table): void {
            $tmdb = fn (string $column): string => "_tmdb_{$column}";

            $table->dropColumn([
                ...array_map($tmdb, [
                    'id', 'imdb_id', 'title', 'original_title', 'original_language',
                    'overview', 'tagline', 'homepage', 'status', 'release_date',
                    'runtime', 'budget', 'revenue', 'popularity', 'vote_average',
                    'vote_count', 'video', 'genres', 'origin_country', 'production_companies',
                    'production_countries', 'spoken_languages', 'belongs_to_collection',
                    'release_dates', 'poster_path', 'backdrop_path',
                ]),
                'tmdb_synced_at',
            ]);

            $table->string('imdb_id')->nullable(false)->change();
            $table->text('title')->nullable(false)->change();
            $table->string('title_type')->nullable(false)->change();
        });
    }
};
