<?php

declare(strict_types=1);

use App\Domains\Local\LaborForest\RunLogVerdict;

/**
 * Fixture provenance: tests/Fixtures/Local/laborforest/up-success.yaml is a
 * byte-exact copy of a real LaborForest run log —
 * `.laborforest/ignored/logs/20260909T171446Z_…_up.yaml`, a successful `up` on
 * this branch — committed unedited. It is the only capture used here, and it is
 * used for exactly one reason: 13 of its 14 steps carry an `exitCode` and the
 * skipped one ('Generate the application key', `skip_reason: unless-matched`)
 * carries none at all. A verdict that reads a MISSING `exitCode` as a failure
 * calls every successful `up` run a failure, and no hand-written document would
 * have taught us that shape.
 *
 * Every other document below is an obviously-synthetic inline string: no failed
 * or aborted run exists to capture, and teardown's orphan lines only appear on a
 * run that lost a resource. A fabricated file pretending to be a capture would
 * be worse than a string that admits what it is, so each is kept minimal — the
 * one field under test sits beside its assertion.
 */
describe('from() run verdict', function (): void {
    it('reports success when the status is success and every step exited 0', function (): void {
        // Arrange
        $yaml = <<<'YAML'
        resource_type: run_log
        name: up
        status: success
        exception: null
        steps:
            - name: 'Fetch from origin'
              type: shell
              exitCode: 0
              output: ''
            - name: 'Run migrations'
              type: shell
              exitCode: 0
              output: ''
        YAML;

        // Act
        $verdict = RunLogVerdict::from($yaml);

        // Assert
        expect($verdict->succeeded)->toBeTrue();
        expect($verdict->failedStep)->toBeNull();
    });

    it('treats a skipped step carrying no exitCode as success', function (): void {
        // The discriminating case, run against real data: 'Generate the
        // application key' is skipped by its `unless:` and so has no `exitCode`
        // key whatsoever. `unless-matched` is a normal skip, not a failure.
        // Arrange
        $yaml = fixtureBytes('Local/laborforest/up-success.yaml');

        // Act
        $verdict = RunLogVerdict::from($yaml);

        // Assert
        expect($verdict->succeeded)->toBeTrue();
        expect($verdict->failedStep)->toBeNull();
    });

    it('fails, naming the step whose exitCode is non-zero', function (): void {
        // Arrange
        $yaml = <<<'YAML'
        resource_type: run_log
        name: up
        status: failed
        exception: null
        steps:
            - name: 'Fetch from origin'
              type: shell
              exitCode: 0
              output: ''
            - name: 'Install Composer dependencies'
              type: shell
              exitCode: 1
              output: ''
        YAML;

        // Act
        $verdict = RunLogVerdict::from($yaml);

        // Assert
        expect($verdict->succeeded)->toBeFalse();
        expect($verdict->failedStep)->toBe('Install Composer dependencies');
    });

    it('fails on a step aborted under a success status', function (): void {
        // Arrange
        $yaml = <<<'YAML'
        resource_type: run_log
        name: up
        status: success
        exception: null
        steps:
            - name: 'Derive workspace env values'
              type: shell
              output: ''
              skip_reason: aborted
        YAML;

        // Act
        $verdict = RunLogVerdict::from($yaml);

        // Assert
        expect($verdict->succeeded)->toBeFalse();
        expect($verdict->failedStep)->toBe('Derive workspace env values');
    });

    it('fails when the top-level status is not success', function (): void {
        // Arrange
        $yaml = <<<'YAML'
        resource_type: run_log
        name: up
        status: error
        exception: 'The workflow could not be started.'
        steps: []
        YAML;

        // Act
        $verdict = RunLogVerdict::from($yaml);

        // Assert
        expect($verdict->succeeded)->toBeFalse();
    });

    it('fails without a step name when the failing step carries no name', function (): void {
        // A step is a map the workflow runner wrote; a run killed mid-write can
        // leave one whose `name` never landed. Reading the key blind warns and
        // reports the failure against an empty name. `status: success` here is the
        // load-bearing half: a nameless failure must still sink the run, never pass
        // as clean because there was nothing to name.
        // Arrange
        $yaml = <<<'YAML'
        resource_type: run_log
        name: up
        status: success
        exception: null
        steps:
            - type: shell
              exitCode: 1
              output: ''
        YAML;

        // Act
        $verdict = RunLogVerdict::from($yaml);

        // Assert
        expect($verdict->succeeded)->toBeFalse();
        expect($verdict->failedStep)->toBeNull();
    });

    it('fails without a step name when the failing step name is empty', function (): void {
        // Arrange
        $yaml = <<<'YAML'
        resource_type: run_log
        name: up
        status: failed
        exception: null
        steps:
            - name: ''
              type: shell
              exitCode: 1
              output: ''
        YAML;

        // Act
        $verdict = RunLogVerdict::from($yaml);

        // Assert
        expect($verdict->succeeded)->toBeFalse();
        expect($verdict->failedStep)->toBeNull();
    });
});

describe('from() unreadable documents', function (): void {
    it('reports a failure rather than throwing on YAML that cannot be parsed', function (): void {
        // The threat model is a run killed mid-write, and a fragment cut inside a
        // quoted scalar never parses at all — the shape checks below are only
        // reached by a document that does.
        // Arrange
        $yaml = <<<'YAML'
        resource_type: run_log
        name: up
        status: 'success
        steps: []
        YAML;

        // Act
        $verdict = RunLogVerdict::from($yaml);

        // Assert
        expect($verdict->succeeded)->toBeFalse();
        expect($verdict->failedStep)->toBeNull();
        expect($verdict->orphans)->toBe([]);
    });

    it('reports a failure when one step in the list is not a map', function (): void {
        // A truncated list entry is a bare scalar, not a map — dropping it instead
        // would leave `status: success` with nothing left to fail on.
        // Arrange
        $yaml = <<<'YAML'
        resource_type: run_log
        name: up
        status: success
        exception: null
        steps:
            - name: 'Fetch from origin'
              type: shell
              exitCode: 0
              output: ''
            - 'Run migrations'
        YAML;

        // Act
        $verdict = RunLogVerdict::from($yaml);

        // Assert
        expect($verdict->succeeded)->toBeFalse();
        expect($verdict->failedStep)->toBeNull();
        expect($verdict->orphans)->toBe([]);
    });
});

describe('from() orphan lines', function (): void {
    it('collects the orphaned database and site lines out of step output', function (): void {
        // down.yaml's destructive steps swallow their own failure with `|| echo`
        // and exit 0 by design, so an orphan can NEVER show in an exit code —
        // the line in the step's output is the only evidence there is.
        // Arrange
        $yaml = <<<'YAML'
        resource_type: run_log
        name: down
        status: success
        exception: null
        steps:
            - name: 'Drop MySQL database'
              type: shell
              exitCode: 0
              output: "  [orphaned database lf_flix_303_consolidate_the_laborfor_f4e9ec]\n"
            - name: 'Remove Laravel Herd site'
              type: shell
              exitCode: 0
              output: "  [orphaned site lf-flix-303-consolidate-the-laborfor-f4e9ec]\n"
        YAML;

        // Act
        $verdict = RunLogVerdict::from($yaml);

        // Assert
        expect($verdict->orphans)->toBe([
            '[orphaned database lf_flix_303_consolidate_the_laborfor_f4e9ec]',
            '[orphaned site lf-flix-303-consolidate-the-laborfor-f4e9ec]',
        ]);
    });

    it('leaves a command heartbeat out of the orphans it collects', function (): void {
        // Every artisan command a step shells to emits `  [tag value]` heartbeats
        // into that step's output, so the two-space bracket indent alone can never
        // be the match — only the `[orphaned ` prefix teardown writes.
        // Arrange
        $yaml = <<<'YAML'
        resource_type: run_log
        name: down
        status: success
        exception: null
        steps:
            - name: 'Dump the database'
              type: shell
              exitCode: 0
              output: "Dumping tables…\n  [dump movies 240]\n  [orphaned database lf_flix_303_consolidate_the_laborfor_f4e9ec]\n  [dump shows 120]\nDone.\n"
        YAML;

        // Act
        $verdict = RunLogVerdict::from($yaml);

        // Assert
        expect($verdict->orphans)->toBe([
            '[orphaned database lf_flix_303_consolidate_the_laborfor_f4e9ec]',
        ]);
    });

    it('collects nothing from a step whose output is all heartbeats', function (): void {
        // Arrange
        $yaml = <<<'YAML'
        resource_type: run_log
        name: down
        status: success
        exception: null
        steps:
            - name: 'Dump the database'
              type: shell
              exitCode: 0
              output: "  [dump movies 240]\n  [dump shows 120]\n"
        YAML;

        // Act
        $verdict = RunLogVerdict::from($yaml);

        // Assert
        expect($verdict->orphans)->toBe([]);
    });
});
