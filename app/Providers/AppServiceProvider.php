<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;
use App\Domains\Download\Actions\FindAcquirableDownloads;
use App\Domains\Download\Actions\QueueDownload;
use App\Domains\Download\Contracts\FindsAcquirableDownloads;
use App\Domains\Download\Contracts\QueuesDownload;
use App\Domains\Identity\Models\User;
use App\Domains\Notifications\Listeners\StoreSlackMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    #[\Override]
    public function register(): void
    {
        $this->app->bind(FindsAcquirableDownloads::class, FindAcquirableDownloads::class);
        $this->app->bind(QueuesDownload::class, QueueDownload::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::unguard();

        Relation::enforceMorphMap([
            'movie' => Movie::class,
            'show' => Show::class,
            'user' => User::class,
        ]);

        Event::listen(NotificationSent::class, StoreSlackMessage::class);
    }
}
