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
            $table->renameColumn('_imdb_num_votes', '_imdb_numVotes');
            $table->renameColumn('_imdb_average_rating', '_imdb_averageRating');
        });

        Schema::table('movies', function (Blueprint $table): void {
            $table->string('_imdb_titleType')->nullable();
            $table->text('_imdb_primaryTitle')->nullable();
            $table->text('_imdb_originalTitle')->nullable();
            $table->unsignedSmallInteger('_imdb_startYear')->nullable();
            $table->unsignedSmallInteger('_imdb_endYear')->nullable();
            $table->unsignedInteger('_imdb_runtimeMinutes')->nullable();
            $table->json('_imdb_genres')->nullable();
            $table->json('_imdb_akas')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('movies', function (Blueprint $table): void {
            $table->dropColumn([
                '_imdb_titleType',
                '_imdb_primaryTitle',
                '_imdb_originalTitle',
                '_imdb_startYear',
                '_imdb_endYear',
                '_imdb_runtimeMinutes',
                '_imdb_genres',
                '_imdb_akas',
            ]);
        });

        Schema::table('movies', function (Blueprint $table): void {
            $table->renameColumn('_imdb_numVotes', '_imdb_num_votes');
            $table->renameColumn('_imdb_averageRating', '_imdb_average_rating');
        });
    }
};
