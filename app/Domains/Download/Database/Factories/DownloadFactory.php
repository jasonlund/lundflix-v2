<?php

declare(strict_types=1);

namespace App\Domains\Download\Database\Factories;

use App\Domains\Download\Models\Download;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Download>
 */
final class DownloadFactory extends Factory
{
    protected $model = Download::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            '_provider_id' => fake()->unique()->numberBetween(1, 1_000_000),
            '_provider_name' => 'Some.Release.1080p.x265',
            '_provider_filename' => 'Some.Release.1080p.x265',
            '_provider_category' => '72',
            '_provider_subcategory' => 'Movie/x265/1080p',
            '_provider_size_bytes' => fake()->numberBetween(1_000_000, 10_000_000_000),
            '_provider_availability' => fake()->numberBetween(1, 100),
            '_provider_demand' => fake()->numberBetween(1, 100),
            '_provider_uploader' => fake()->userName(),
            '_provider_published_at' => now(),
            '_provider_files' => [['name' => 'file.bin', 'size_bytes' => 1_000_000]],
            '_provider_description' => ['text' => '<b>Title : Some Release</b><br>', 'screenshots' => ['https://example.test/a.jpg', 'https://example.test/b.jpg']],
            'quality' => '1080p',
            'codec' => 'hevc',
            'source' => 'web-dl',
            'release_tag' => 'none',
            'is_rar' => true,
            'index_synced_at' => now(),
            'rss_synced_at' => now(),
            'detail_synced_at' => now(),
            'filelist_synced_at' => now(),
        ];
    }
}
