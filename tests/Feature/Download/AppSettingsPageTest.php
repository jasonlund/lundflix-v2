<?php

declare(strict_types=1);

use App\Domains\Download\Settings\DownloadSettings;
use App\Domains\Identity\Models\User;
use App\Filament\Pages\AppSettings;
use Livewire\Livewire;

it('loads the page with the current cookie values', function () {
    // Arrange
    $this->actingAs(User::factory()->create());

    // Act
    $page = Livewire::test(AppSettings::class);

    // Assert
    $page->assertFormSet([
        'uid' => 'test-uid',
        'pass' => 'test-pass',
    ]);
});

it('persists rotated cookie values', function () {
    // Arrange
    $this->actingAs(User::factory()->create());

    // Act
    $page = Livewire::test(AppSettings::class)
        ->fillForm([
            'uid' => 'rotated-uid',
            'pass' => 'rotated-pass',
        ])
        ->call('save');

    // Assert
    $page->assertHasNoFormErrors();
    app()->forgetInstance(DownloadSettings::class);
    expect(app(DownloadSettings::class)->uid)->toBe('rotated-uid');
    expect(app(DownloadSettings::class)->pass)->toBe('rotated-pass');
});
