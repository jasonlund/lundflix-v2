<?php

declare(strict_types=1);

use App\Domains\Local\Database\ColumnOrder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Each list restates the raw-source convention in `.ai/guidelines/project.md` as
     * physical order: columns group by source `imdb → tmdb → tvdb`, each source's
     * `*_synced_at` closes its block, and `created_at`/`updated_at` come last.
     *
     * A list must name every column of its table exactly once — `ColumnOrder` refuses
     * anything else rather than build a partial `AFTER` chain.
     *
     * @var array<string, list<string>>
     */
    private const array TARGET = [
        'movies' => [
            'id',
            '_imdb_id',
            '_imdb_titleType',
            '_imdb_primaryTitle',
            '_imdb_originalTitle',
            '_imdb_startYear',
            '_imdb_endYear',
            '_imdb_runtimeMinutes',
            '_imdb_genres',
            '_imdb_averageRating',
            '_imdb_numVotes',
            '_imdb_akas',
            '_tmdb_id',
            '_tmdb_title',
            '_tmdb_original_title',
            '_tmdb_original_language',
            '_tmdb_overview',
            '_tmdb_tagline',
            '_tmdb_homepage',
            '_tmdb_status',
            '_tmdb_release_date',
            '_tmdb_runtime',
            '_tmdb_budget',
            '_tmdb_revenue',
            '_tmdb_popularity',
            '_tmdb_vote_average',
            '_tmdb_vote_count',
            '_tmdb_video',
            '_tmdb_genres',
            '_tmdb_origin_country',
            '_tmdb_production_companies',
            '_tmdb_production_countries',
            '_tmdb_spoken_languages',
            '_tmdb_belongs_to_collection',
            '_tmdb_release_dates',
            '_tmdb_poster_path',
            '_tmdb_backdrop_path',
            'tmdb_synced_at',
            'created_at',
            'updated_at',
        ],
        'shows' => [
            'id',
            '_imdb_id',
            '_imdb_titleType',
            '_imdb_primaryTitle',
            '_imdb_originalTitle',
            '_imdb_startYear',
            '_imdb_endYear',
            '_imdb_runtimeMinutes',
            '_imdb_genres',
            '_imdb_averageRating',
            '_imdb_numVotes',
            '_imdb_akas',
            '_tmdb_id',
            '_tmdb_name',
            '_tmdb_original_name',
            '_tmdb_original_language',
            '_tmdb_overview',
            '_tmdb_tagline',
            '_tmdb_status',
            '_tmdb_first_air_date',
            '_tmdb_popularity',
            '_tmdb_vote_average',
            '_tmdb_vote_count',
            '_tmdb_genres',
            '_tmdb_poster_path',
            '_tmdb_backdrop_path',
            '_tmdb_external_ids',
            'tmdb_synced_at',
            '_tvdb_id',
            '_tvdb_name',
            '_tvdb_slug',
            '_tvdb_overview',
            '_tvdb_score',
            '_tvdb_firstAired',
            '_tvdb_lastAired',
            '_tvdb_year',
            '_tvdb_averageRuntime',
            '_tvdb_status',
            '_tvdb_originalLanguage',
            '_tvdb_originalCountry',
            '_tvdb_genres',
            '_tvdb_remoteIds',
            '_tvdb_defaultSeasonType',
            'tvdb_synced_at',
            'episodes_synced_at',
            'created_at',
            'updated_at',
        ],
        'media' => [
            'id',
            'mediable_type',
            'mediable_id',
            'type',
            'is_active',
            '_tmdb_file_path',
            '_tmdb_iso_639_1',
            '_tmdb_iso_3166_1',
            '_tmdb_vote_average',
            '_tmdb_vote_count',
            '_tmdb_width',
            '_tmdb_height',
            '_tmdb_aspect_ratio',
            '_tvdb_image',
            '_tvdb_type',
            '_tvdb_language',
            '_tvdb_width',
            '_tvdb_height',
            '_tvdb_score',
            '_tvdb_thumbnail',
            'created_at',
            'updated_at',
        ],
        'users' => [
            'id',
            'name',
            'email',
            'email_verified_at',
            'password',
            'two_factor_secret',
            'two_factor_recovery_codes',
            'two_factor_confirmed_at',
            'remember_token',
            '_plex_id',
            '_plex_uuid',
            '_plex_username',
            '_plex_thumb',
            '_plex_token',
            'created_at',
            'updated_at',
        ],
        'plex_movies' => [
            'id',
            'plex_server_id',
            'plex_library_id',
            '_plex_ratingKey',
            '_plex_guid',
            '_plex_guids',
            '_plex_title',
            '_plex_year',
            '_plex_addedAt',
            '_plex_updatedAt',
            '_imdb_id',
            '_tmdb_id',
            '_tvdb_id',
            'synced_at',
            'announced_at',
            'created_at',
            'updated_at',
        ],
        'plex_episodes' => [
            'id',
            'plex_server_id',
            'plex_show_id',
            'plex_season_id',
            '_plex_ratingKey',
            '_plex_guid',
            '_plex_parentIndex',
            '_plex_index',
            '_plex_title',
            '_plex_addedAt',
            '_plex_updatedAt',
            '_plex_guids',
            '_imdb_id',
            '_tmdb_id',
            '_tvdb_id',
            'synced_at',
            'announced_at',
            'created_at',
            'updated_at',
        ],
    ];

    public function up(): void
    {
        // `MODIFY COLUMN … AFTER …` is MySQL-only DDL — sqlite cannot reposition a
        // column at all, and the test suite replays every migration on sqlite.
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        // MySQL auto-commits DDL and its grammar reports no schema transactions, so the
        // migrator cannot roll the loop back — a failure part-way leaves earlier tables
        // reordered and this migration unlogged. Re-running `migrate` is safe: reordering
        // an already-correct table is a no-op, so the retry just finishes the remainder.
        foreach (self::TARGET as $table => $order) {
            $columns = array_map(
                fn (object $column): array => (array) $column,
                DB::select(sprintf('SHOW FULL COLUMNS FROM `%s`', $table)),
            );

            DB::statement(ColumnOrder::alterStatement($table, $columns, $order));
        }
    }

    public function down(): void
    {
        // Column order carries no data, so restoring the historical scramble buys nothing.
    }
};
