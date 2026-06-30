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
        Schema::create('shows', function (Blueprint $table): void {
            $table->id();
            $table->string('_imdb_id')->unique();
            $table->text('_imdb_primary_title');
            $table->string('_imdb_title_type');
            $table->unsignedSmallInteger('_imdb_start_year')->nullable()->index();
            $table->unsignedSmallInteger('_imdb_end_year')->nullable();
            $table->unsignedInteger('_imdb_runtime_minutes')->nullable();
            $table->json('_imdb_genres')->nullable();
            $table->unsignedInteger('_imdb_num_votes')->nullable();
            $table->decimal('_imdb_average_rating', 3, 1)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shows');
    }
};
