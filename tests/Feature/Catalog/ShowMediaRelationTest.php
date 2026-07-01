<?php

declare(strict_types=1);

use App\Domains\Catalog\Models\Media;
use App\Domains\Catalog\Models\Show;
use Illuminate\Database\Eloquent\Collection;

it('returns the show media rows via the morphMany relation', function (): void {
    // Arrange
    $show = Show::factory()->create();
    Media::factory()->count(2)->for($show, 'mediable')->create();

    // Act
    $show->refresh();
    $media = $show->media;

    // Assert
    expect($media)->toBeInstanceOf(Collection::class)
        ->and($media)->toHaveCount(2)
        ->and($media[0])->toBeInstanceOf(Media::class)
        ->and($media[1])->toBeInstanceOf(Media::class);
});

it('resolves the parent show via the mediable morphTo relation', function (): void {
    // Arrange
    $show = Show::factory()->create();
    $media = Media::factory()->for($show, 'mediable')->create();

    // Act
    $parent = Media::query()->findOrFail($media->id)->mediable;

    // Assert
    expect($parent)->toBeInstanceOf(Show::class)
        ->and($parent->is($show))->toBeTrue();
});
