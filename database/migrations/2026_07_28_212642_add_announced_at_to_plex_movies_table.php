<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('plex_movies', function (Blueprint $table): void {
            $table->timestamp('announced_at')->nullable()->index();
        });

        // Everything already mirrored predates announcements; stamping it as
        // announced stops an existing library announcing its whole back catalogue
        // on the first run after deploy.
        DB::table('plex_movies')->update(['announced_at' => now()]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('plex_movies', function (Blueprint $table): void {
            $table->dropIndex(['announced_at']);
            $table->dropColumn('announced_at');
        });
    }
};
