<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Movie;
use Illuminate\Database\Eloquent\Model;

it('unguards Eloquent models globally', function (): void {
    // Act
    $unguarded = Model::isUnguarded();

    // Assert
    expect($unguarded)->toBeTrue();
});

it('mass-assigns an attribute not declared fillable', function (): void {
    // Arrange
    $attributes = ['not_a_declared_fillable' => 'Heat'];

    // Act
    $movie = new Movie($attributes);

    // Assert
    expect($movie->not_a_declared_fillable)->toBe('Heat');
});
