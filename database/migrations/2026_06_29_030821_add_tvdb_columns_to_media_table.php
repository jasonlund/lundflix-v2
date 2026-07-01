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
        Schema::table('media', function (Blueprint $table): void {
            $tvdb = fn (string $column): string => "_tvdb_{$column}";

            $table->string($tvdb('image'))->nullable();
            $table->unsignedInteger($tvdb('type'))->nullable();
            $table->string($tvdb('language'))->nullable();
            $table->unsignedInteger($tvdb('width'))->nullable();
            $table->unsignedInteger($tvdb('height'))->nullable();
            $table->unsignedBigInteger($tvdb('score'))->nullable();
            $table->string($tvdb('thumbnail'))->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->dropColumn([
                '_tvdb_image',
                '_tvdb_type',
                '_tvdb_language',
                '_tvdb_width',
                '_tvdb_height',
                '_tvdb_score',
                '_tvdb_thumbnail',
            ]);
        });
    }
};
