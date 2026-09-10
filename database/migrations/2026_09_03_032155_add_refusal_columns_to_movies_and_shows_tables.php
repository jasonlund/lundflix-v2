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
            $table->boolean('_imdb_isAdult')->nullable()->after('_imdb_originalTitle');

            // TMDB /tv payloads carry no `video` key, so only movies get _tmdb_video.
            $table->after('_tmdb_video', function (Blueprint $table): void {
                $table->boolean('_tmdb_adult')->nullable();
                $table->boolean('_tmdb_softcore')->nullable();
            });
        });

        Schema::table('shows', function (Blueprint $table): void {
            $table->boolean('_imdb_isAdult')->nullable()->after('_imdb_originalTitle');

            $table->after('_tmdb_vote_count', function (Blueprint $table): void {
                $table->boolean('_tmdb_adult')->nullable();
                $table->boolean('_tmdb_softcore')->nullable();
            });
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('movies', function (Blueprint $table): void {
            $table->dropColumn([
                '_imdb_isAdult',
                '_tmdb_adult',
                '_tmdb_softcore',
            ]);
        });

        Schema::table('shows', function (Blueprint $table): void {
            $table->dropColumn([
                '_imdb_isAdult',
                '_tmdb_adult',
                '_tmdb_softcore',
            ]);
        });
    }
};
