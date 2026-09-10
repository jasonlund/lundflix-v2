<?php

declare(strict_types=1);

namespace App\Domains\Local\Console\Commands;

use App\Domains\Local\Support\BranchName;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Print the branch name derived from a comma-separated ticket id list and a title')]
#[Signature('lf:branch-name {tickets} {title}')]
final class DeriveBranchName extends Command
{
    public function handle(): int
    {
        $ticketIds = explode(',', (string) $this->argument('tickets'));

        // The bare branch, with no phase line, heartbeat or `Done.`: /worktree:up
        // reads this through $(php artisan lf:branch-name …), so any extra line
        // would land inside the branch name it captures.
        $this->output->writeln(BranchName::derive($ticketIds, (string) $this->argument('title')));

        return self::SUCCESS;
    }
}
