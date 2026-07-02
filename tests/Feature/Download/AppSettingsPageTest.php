<?php

declare(strict_types=1);

use App\Domains\Download\Settings\DownloadSettings;
use App\Domains\Identity\Models\User;
use App\Filament\Pages\AppSettings;
use Livewire\Livewire;

it('loads the page with the current uid but never the plaintext pass', function (): void {
    // Arrange
    $this->actingAs(User::factory()->create());

    // Act
    $page = Livewire::test(AppSettings::class);

    // Assert
    $page->assertFormSet(['uid' => 'test-uid']);
    expect($page->get('data')['pass'])->toBe('');
});

it('never ships the plaintext pass in the livewire snapshot', function (): void {
    // Arrange
    $this->actingAs(User::factory()->create());

    // Act
    $html = Livewire::test(AppSettings::class)->html();

    // Assert
    expect($html)->not->toContain('test-pass');
});

it('persists a rotated uid and pass', function (): void {
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
    expect(resolve(DownloadSettings::class)->uid)->toBe('rotated-uid');
    expect(resolve(DownloadSettings::class)->pass)->toBe('rotated-pass');
});

it('keeps the stored pass when submitted blank', function (): void {
    // Arrange
    $this->actingAs(User::factory()->create());

    // Act
    $page = Livewire::test(AppSettings::class)
        ->fillForm([
            'uid' => 'rotated-uid',
            'pass' => '',
        ])
        ->call('save');

    // Assert
    $page->assertHasNoFormErrors();
    app()->forgetInstance(DownloadSettings::class);
    expect(resolve(DownloadSettings::class)->uid)->toBe('rotated-uid');
    expect(resolve(DownloadSettings::class)->pass)->toBe('test-pass');
});
