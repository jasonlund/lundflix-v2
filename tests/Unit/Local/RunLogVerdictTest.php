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
});
