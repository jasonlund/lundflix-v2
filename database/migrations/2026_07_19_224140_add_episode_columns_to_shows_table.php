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
            $table->unsignedInteger('_tvdb_defaultSeasonType')->nullable();
            $table->timestamp('episodes_synced_at')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shows', function (Blueprint $table): void {
            $table->dropColumn(['_tvdb_defaultSeasonType', 'episodes_synced_at']);
        });
    }
};
