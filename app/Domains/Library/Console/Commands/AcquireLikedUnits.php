<?php

declare(strict_types=1);

namespace App\Domains\Library\Console\Commands;

use App\Domains\Common\Console\Concerns\EmitsHeartbeat;
use App\Domains\Library\Actions\CloseAcquiredUnits;
use App\Domains\Library\Actions\QueueAcquisitions;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Description('Queue a download for every liked unit missing from the server')]
#[Signature('library:acquire')]
final class AcquireLikedUnits extends Command
{
    use EmitsHeartbeat;

    public function handle(CloseAcquiredUnits $closeAcquiredUnits, QueueAcquisitions $queueAcquisitions): int
    {
        // Close first: a unit that landed since the last run is settled before the
        // queue pass decides what still needs fetching.
        $this->output->writeln('Closing acquired units…');
        $this->flushTotal('acquire closed', $closeAcquiredUnits->handle());

        $this->output->writeln('Queuing liked units…');
        $counts = $queueAcquisitions->handle();
        $this->flushTotal('acquire queued', $counts->queued);

        $this->failureSummary($counts->failed, Str::plural('fetch', $counts->failed), 'not recorded, retried next run');

        $this->output->writeln('Done.');

        return $counts->failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
