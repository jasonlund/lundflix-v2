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
        Schema::create('like_behaviors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('like_id')->constrained()->cascadeOnDelete();
            $table->string('behavior');
            // Null is the off state, so toggling a behavior back on records when.
            $table->timestamp('enabled_at')->nullable();
            $table->timestamps();

            $table->unique(['like_id', 'behavior']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('like_behaviors');
    }
};
