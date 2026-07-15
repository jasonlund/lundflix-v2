<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;
use App\Domains\Download\Models\Download;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('resolves the Movie sharing its _imdb_id', function (): void {
    // Arrange
    $movie = Movie::factory()->create(['_imdb_id' => 'tt1234567']);
    $download = Download::factory()->create(['_imdb_id' => 'tt1234567']);

    // Act
    $resolved = $download->movie;

    // Assert
    expect($resolved?->is($movie))->toBeTrue();
});

it('resolves the Show sharing its _imdb_id', function (): void {
    // Arrange
    $show = Show::factory()->create(['_imdb_id' => 'tt7654321']);
    $download = Download::factory()->create(['_imdb_id' => 'tt7654321']);

    // Act
    $resolved = $download->show;

    // Assert
    expect($resolved?->is($show))->toBeTrue();
});

it('resolves both relations to null for a null _imdb_id', function (): void {
    // Arrange
    // an unmatched, index-sourced row carries no imdb id

    // Act
    $download = Download::factory()->create(['_imdb_id' => null]);

    // Assert
    expect($download->movie)->toBeNull();
    expect($download->show)->toBeNull();
});
