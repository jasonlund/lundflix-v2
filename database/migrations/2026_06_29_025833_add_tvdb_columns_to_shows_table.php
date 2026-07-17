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
            $tvdb = fn (string $column): string => "_tvdb_{$column}";

            $table->unsignedInteger($tvdb('id'))->nullable()->unique();
            $table->text($tvdb('name'))->nullable();
            $table->string($tvdb('slug'))->nullable();
            $table->text($tvdb('overview'))->nullable();
            $table->float($tvdb('score'))->nullable();
            $table->date($tvdb('firstAired'))->nullable();
            $table->date($tvdb('lastAired'))->nullable();
            $table->string($tvdb('year'))->nullable();
            $table->unsignedInteger($tvdb('averageRuntime'))->nullable();
            $table->json($tvdb('status'))->nullable();
            $table->string($tvdb('originalLanguage'))->nullable();
            $table->string($tvdb('originalCountry'))->nullable();
            $table->json($tvdb('genres'))->nullable();
            $table->json($tvdb('remoteIds'))->nullable();

            $table->timestamp('tvdb_synced_at')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shows', function (Blueprint $table): void {
            $tvdb = fn (string $column): string => "_tvdb_{$column}";

            $table->dropColumn([
                ...array_map($tvdb, [
                    'id', 'name', 'slug', 'overview', 'score', 'firstAired',
                    'lastAired', 'year', 'averageRuntime', 'status',
                    'originalLanguage', 'originalCountry', 'genres', 'remoteIds',
                ]),
                'tvdb_synced_at',
            ]);
        });
    }
};
