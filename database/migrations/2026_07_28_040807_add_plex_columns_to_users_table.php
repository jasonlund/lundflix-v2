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
        Schema::table('users', function (Blueprint $table): void {
            $plex = fn (string $column): string => "_plex_{$column}";

            $table->string($plex('id'))->nullable()->unique();
            $table->string($plex('uuid'))->nullable();
            $table->string($plex('username'))->nullable();
            $table->string($plex('thumb'))->nullable();
            $table->text($plex('token'))->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $plex = fn (string $column): string => "_plex_{$column}";

            // Dropped explicitly first: sqlite's ALTER TABLE DROP COLUMN refuses a
            // column an index still references ("error in index ... after drop column").
            $table->dropUnique([$plex('id')]);

            $table->dropColumn(array_map($plex, [
                'id', 'uuid', 'username', 'thumb', 'token',
            ]));
        });
    }
};
