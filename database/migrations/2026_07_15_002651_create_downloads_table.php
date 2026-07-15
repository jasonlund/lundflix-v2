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
        Schema::create('downloads', function (Blueprint $table): void {
            $table->id();

            $table->string('_imdb_id')->nullable()->index();

            $table->unsignedBigInteger('_tmdb_id')->nullable()->index();

            $table->unsignedBigInteger('_provider_id')->unique();
            $table->string('_provider_name');
            $table->string('_provider_filename');
            $table->string('_provider_category');
            $table->string('_provider_subcategory');
            $table->unsignedBigInteger('_provider_size_bytes');
            $table->unsignedInteger('_provider_availability');
            $table->unsignedInteger('_provider_demand');
            $table->string('_provider_uploader')->nullable();
            $table->timestamp('_provider_published_at')->nullable();
            $table->json('_provider_files')->nullable();
            $table->json('_provider_description')->nullable();

            $table->string('quality')->nullable();
            $table->string('codec');
            $table->string('source');
            $table->string('release_tag');
            $table->boolean('is_rar');

            $table->timestamp('index_synced_at')->nullable();
            $table->timestamp('rss_synced_at')->nullable();
            $table->timestamp('detail_synced_at')->nullable();
            $table->timestamp('filelist_synced_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('downloads');
    }
};
