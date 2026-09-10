<?php

declare(strict_types=1);

namespace App\Domains\Local\LaborForest;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The success/failure judgment of one LaborForest workflow run, read from its
 * run log rather than from the MCP call that dispatched it — `run-workflow`
 * only dispatches, so its return says nothing about the run.
 */
final readonly class RunLogVerdict
{
    /**
     * Teardown's `|| echo` fallbacks emit this shape, and only this shape — a bare
     * `  [` match would also swallow every command heartbeat (`  [dump movies 240]`)
     * that a step's stdout carries.
     */
    private const string ORPHAN_PREFIX = '  [orphaned ';

    /**
     * @param  list<string>  $orphans
     */
    private function __construct(
        public bool $succeeded,
        public ?string $failedStep,
        public array $orphans,
    ) {}

    public static function from(string $yaml): self
    {
        try {
            $log = (array) Yaml::parse($yaml);
        } catch (ParseException) {
            // A fragment cut mid-write (unterminated quote, dangling block scalar)
            // does not parse at all, and is the same unreadable document as one that
            // parses into a shape we can't read — never a success either way.
            return new self(false, null, []);
        }

        $steps = self::stepsIn($log);

        // The shape a run killed mid-write leaves on disk; see stepsIn() for why an
        // unreadable document can never report as a success.
        if (! $steps instanceof Collection) {
            return new self(false, null, []);
        }

        $failed = $steps->first(static fn (array $step): bool => self::stepFailed($step));
        // A half-written step can lack `name` entirely; an absent or empty one reads
        // as "no name to report", never as a failure against an empty string.
        $failedName = $failed === null ? '' : (string) ($failed['name'] ?? '');
        $failedStep = $failedName === '' ? null : $failedName;

        // Success turns on whether a step failed, NOT on whether one could be named —
        // a nameless failing step nulls `failedStep` and would otherwise pass as clean.
        $succeeded = ($log['status'] ?? null) === 'success' && $failed === null;

        return new self($succeeded, $failedStep, self::orphansIn($steps));
    }

    /**
     * The log's step list, or null when `steps:` is anything other than a list of maps.
     *
     * Null is the whole point of the return type: null is not "no steps", it is "this
     * document cannot be read", which every caller must treat as a run that did not
     * succeed. Dropping the unreadable entries instead would leave a truncated log
     * showing `status: success` with nothing left to fail on.
     *
     * @param  array<mixed, mixed>  $log
     * @return Collection<int, array<string, mixed>>|null
     */
    private static function stepsIn(array $log): ?Collection
    {
        $steps = $log['steps'] ?? [];

        if (! is_array($steps)) {
            return null;
        }

        $collected = collect($steps)->values();

        return $collected->every(static fn (mixed $step): bool => is_array($step)) ? $collected : null;
    }

    /**
     * `aborted` is the one skip kind that means failure: it marks a step the run gave
     * up on, not one that declined itself the way `unless-matched` does. Lumping every
     * skip together reports a run that quit part-way as a clean success.
     *
     * @param  array<string, mixed>  $step
     */
    private static function stepFailed(array $step): bool
    {
        if (($step['skip_reason'] ?? null) === 'aborted') {
            return true;
        }

        // A skipped step carries no `exitCode` key at all, so absence must read as
        // "did not run", never as a failure — `?? 1` or an isset() check here calls
        // every successful `up` a failure, since one of its steps is always skipped.
        return array_key_exists('exitCode', $step) && (int) $step['exitCode'] !== 0;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $steps
     * @return list<string>
     */
    private static function orphansIn(Collection $steps): array
    {
        return $steps
            ->flatMap(static fn (array $step): array => explode("\n", (string) ($step['output'] ?? '')))
            ->filter(static fn (string $line): bool => Str::startsWith($line, self::ORPHAN_PREFIX))
            ->map(static fn (string $line): string => Str::trim($line))
            ->values()
            ->all();
    }
}
