<?php

declare(strict_types=1);

namespace App\Domains\Common\Console\Concerns;

/**
 * Shared console heartbeat output. Hosts must be an Illuminate console
 * command — every line goes out through `$this->output->writeln()`.
 */
trait EmitsHeartbeat
{
    /**
     * Highest boundary already emitted, per tag, so one command can beat several
     * tags independently without the caller threading the state back in.
     *
     * @var array<string, int>
     */
    protected array $heartbeatMarks = [];

    /**
     * Emit one heartbeat line unconditionally — the caller owns the cadence.
     * The line still counts as the tag's last mark, so the interval-driven
     * beat() and the closing flushTotal() share one map and never repeat it.
     */
    protected function mark(string $tag, int $total, ?string $suffix = null): void
    {
        $this->heartbeatMarks[$tag] = $total;

        $this->output->writeln("  [{$tag} {$total}]".($suffix === null ? '' : " {$suffix}"));
    }

    /**
     * Emit a heartbeat for every $interval boundary the running total crosses —
     * a single call can advance past several, and each gets its own line at the
     * clean boundary value. Boundary state is tracked per $tag.
     */
    protected function beat(string $tag, int $total, int $interval, ?string $suffix = null): void
    {
        $lastMark = $this->lastMark($tag);

        // Snap to the boundary after the last mark: a ragged mark (147) must
        // resume at 200, and the never-marked sentinel (-1) at the first
        // boundary rather than one short of it.
        $first = intdiv($lastMark, $interval) * $interval + $interval;

        for ($boundary = $first; $boundary <= $total; $boundary += $interval) {
            $this->mark($tag, $boundary, $suffix);
        }
    }

    /**
     * Close a tag with its exact final total, unless the last line already
     * reported that number.
     */
    protected function flushTotal(string $tag, int $total): void
    {
        if ($this->lastMark($tag) === $total) {
            return;
        }

        $this->mark($tag, $total);
    }

    /**
     * Report failures as an unindented plain line: the two-space indent is
     * reserved for `  [tag value]` heartbeats, so an indented summary would read
     * as one more running count rather than a run-level consequence.
     */
    protected function failureSummary(int $count, string $noun, string $consequence): void
    {
        if ($count <= 0) {
            return;
        }

        $this->output->writeln("{$count} {$noun} failed; {$consequence}.");
    }

    /**
     * Defaults to -1, not 0: a run that processed nothing must still emit its
     * zero total, which a 0 default would silently swallow.
     */
    private function lastMark(string $tag): int
    {
        return $this->heartbeatMarks[$tag] ?? -1;
    }
}
