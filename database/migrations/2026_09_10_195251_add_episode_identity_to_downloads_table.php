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
        Schema::table('downloads', function (Blueprint $table): void {
            // App-derived from the release name like quality/codec, so unprefixed.
            $table->after('is_rar', function (Blueprint $table): void {
                $table->unsignedSmallInteger('season')->nullable();
                $table->unsignedSmallInteger('episode')->nullable();
                $table->boolean('is_season_pack')->nullable();
            });
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('downloads', function (Blueprint $table): void {
            $table->dropColumn(['season', 'episode', 'is_season_pack']);
        });
    }
};
