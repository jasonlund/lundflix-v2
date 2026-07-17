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
        Schema::create('media', function (Blueprint $table): void {
            $table->id();
            $table->morphs('mediable');
            $table->string('type');
            $table->boolean('is_active')->default(true);

            $tmdb = fn (string $column): string => "_tmdb_{$column}";

            $table->string($tmdb('file_path'))->nullable();
            $table->string($tmdb('iso_639_1'))->nullable();
            $table->string($tmdb('iso_3166_1'))->nullable();
            $table->float($tmdb('vote_average'))->nullable();
            $table->unsignedInteger($tmdb('vote_count'))->nullable();
            $table->unsignedInteger($tmdb('width'))->nullable();
            $table->unsignedInteger($tmdb('height'))->nullable();
            $table->float($tmdb('aspect_ratio'))->nullable();
            $table->index(['mediable_type', 'mediable_id', 'type']);
            $table->unique(['mediable_type', 'mediable_id', $tmdb('file_path')]);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
