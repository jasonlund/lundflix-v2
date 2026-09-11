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
        Schema::create('like_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('unit_kind');
            $table->unsignedBigInteger('unit_id');
            $table->timestamps();

            // Keyed on the user, not the like: re-liking a title, a re-added file or a second
            // library's copy of the same unit can never tell them about it twice.
            $table->unique(['user_id', 'unit_kind', 'unit_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('like_notifications');
    }
};
