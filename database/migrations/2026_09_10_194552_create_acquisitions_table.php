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
        Schema::create('acquisitions', function (Blueprint $table): void {
            $table->id();
            $table->string('unit_kind');
            $table->unsignedBigInteger('unit_id');
            // No foreign key: a mirrored download row can vanish, and queueing an
            // id naming no row is already a no-op.
            $table->unsignedBigInteger('download_id');
            $table->string('status');
            $table->timestamps();

            // One record per unit, system-owned, however many users like the title.
            $table->unique(['unit_kind', 'unit_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('acquisitions');
    }
};
