<?php

declare(strict_types=1);

use App\Domains\Download\Settings\DownloadSettings;
use App\Domains\Identity\Models\User;
use App\Filament\Pages\AppSettings;
use Livewire\Livewire;

describe('AppSettings form load', function (): void {
    it('loads the page with the current uid but never the plaintext pass', function (): void {
        // Arrange
        $this->actingAs(User::factory()->create());
        $settings = resolve(DownloadSettings::class);
        $settings->uid = 'stored-uid';
        $settings->save();

        // Act
        $page = Livewire::test(AppSettings::class);

        // Assert
        $page->assertFormSet(['uid' => 'stored-uid']);
        expect($page->get('data')['pass'])->toBe('');
    });

    it('never ships a stored plaintext credential in the livewire snapshot', function (): void {
        // The sentinels must actually be persisted first: a leak can only ship a value the
        // page could read, so asserting against a credential that was never stored would
        // hold no matter what mount() fills into the public $data.
        // Arrange
        $this->actingAs(User::factory()->create());
        $settings = resolve(DownloadSettings::class);
        $settings->uid = 'stored-uid-h4rd1e';
        $settings->pass = 'sentinel-pass-qz7w2k';
        $settings->rss_key = 'sentinel-rss-vm9x4t';
        $settings->save();

        // Act
        $html = Livewire::test(AppSettings::class)->html();

        // Assert
        expect($html)->not->toContain('sentinel-pass-qz7w2k');
        expect($html)->not->toContain('sentinel-rss-vm9x4t');
        // Non-vacuity guard: the uid proves the page really rendered the persisted
        // settings, so "neither sentinel present" cannot pass on an empty render.
        expect($html)->toContain('stored-uid-h4rd1e');
    });
});

describe('AppSettings save() persistence', function (): void {
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

    it('persists an entered rss_key', function (): void {
        // Arrange
        $this->actingAs(User::factory()->create());

        // Act
        $page = Livewire::test(AppSettings::class)
            ->fillForm([
                'uid' => 'op-uid',
                'rss_key' => 'entered-rss',
            ])
            ->call('save');

        // Assert
        $page->assertHasNoFormErrors();
        app()->forgetInstance(DownloadSettings::class);
        expect(resolve(DownloadSettings::class)->rss_key)->toBe('entered-rss');
    });

    it('keeps the stored pass when submitted blank', function (): void {
        // Arrange
        $this->actingAs(User::factory()->create());
        $settings = resolve(DownloadSettings::class);
        $settings->pass = 'existing-pass';
        $settings->save();

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
        expect(resolve(DownloadSettings::class)->pass)->toBe('existing-pass');
    });

    it('keeps the stored rss_key when submitted blank', function (): void {
        // Arrange
        $this->actingAs(User::factory()->create());
        $settings = resolve(DownloadSettings::class);
        $settings->rss_key = 'existing-rss';
        $settings->save();

        // Act
        $page = Livewire::test(AppSettings::class)
            ->fillForm([
                'uid' => 'rotated-uid',
                'rss_key' => '',
            ])
            ->call('save');

        // Assert
        $page->assertHasNoFormErrors();
        app()->forgetInstance(DownloadSettings::class);
        expect(resolve(DownloadSettings::class)->uid)->toBe('rotated-uid');
        expect(resolve(DownloadSettings::class)->rss_key)->toBe('existing-rss');
    });
});
