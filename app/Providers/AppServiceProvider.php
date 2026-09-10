<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domains\Catalog\Models\Episode;
use App\Domains\Catalog\Models\Movie;
use App\Domains\Catalog\Models\Show;
use App\Domains\Identity\Models\User;
use App\Domains\Notifications\Listeners\StoreSlackMessage;
use App\Domains\PlexLibrary\Contracts\ReportsPresence;
use App\Domains\PlexLibrary\Services\MirrorPresence;
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
        $this->app->bind(ReportsPresence::class, MirrorPresence::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::unguard();

        // Every alias here is persisted verbatim in a polymorphic *_type column
        // (media.mediable_type, notifications.notifiable_type), so this map is a
        // storage contract. The two that read like UnitKind values restate them on
        // purpose rather than deriving from the enum: renaming a case is a rename,
        // but rewriting an alias is a data migration.
        Relation::enforceMorphMap([
            'episode' => Episode::class,
            'movie' => Movie::class,
            'show' => Show::class,
            'user' => User::class,
        ]);

        Event::listen(NotificationSent::class, StoreSlackMessage::class);
    }
}
