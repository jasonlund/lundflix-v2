<?php

declare(strict_types=1);

namespace App\Domains\Library\Console\Commands;

use App\Domains\Common\Console\Concerns\EmitsHeartbeat;
use App\Domains\Library\Actions\NotifyLikedUnits;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Tell each user which units of the titles they like have newly arrived on the server')]
#[Signature('library:notify')]
final class SweepLikeNotifications extends Command
{
    use EmitsHeartbeat;

    /** Unprefixed: the sweep calls no third-party source, so the tag names the work. */
    private const string HEARTBEAT_TAG = 'notify units';

    public function handle(NotifyLikedUnits $notifyLikedUnits): int
    {
        $this->output->writeln('Notifying liked units…');

        $total = $notifyLikedUnits->handle(onUserNotified: function (int $unitsToldSoFar): void {
            $this->mark(self::HEARTBEAT_TAG, $unitsToldSoFar);
        });

        $this->flushTotal(self::HEARTBEAT_TAG, $total);

        $this->output->writeln('Done.');

        return self::SUCCESS;
    }
}
