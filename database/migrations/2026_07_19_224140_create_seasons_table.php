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
        Schema::create('seasons', function (Blueprint $table): void {
            $tvdb = fn (string $column): string => "_tvdb_{$column}";

            $table->id();
            $table->foreignId('show_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger($tvdb('id'))->nullable()->unique();
            $table->unsignedInteger($tvdb('seriesId'))->nullable();
            $table->json($tvdb('type'))->nullable();
            $table->unsignedInteger($tvdb('number'))->nullable();
            $table->text($tvdb('image'))->nullable();
            $table->unsignedInteger($tvdb('imageType'))->nullable();

            $table->timestamp('tvdb_synced_at')->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seasons');
    }
};
