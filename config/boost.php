<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Test Enforcement
    |--------------------------------------------------------------------------
    |
    | Pins the `=== tests rules ===` block into the generated guideline files.
    | Left unset, `boost:install --guidelines` decides by shelling out to
    | `artisan test --list-tests` and keeping the block only when it counts six
    | or more tests. That probe loads the Browser suite, which throws
    | PlaywrightNotInstalledException in any worktree without `node_modules`, so
    | the count comes back zero and the block is silently dropped from
    | CLAUDE.md and AGENTS.md. Regeneration has to be deterministic — the repo
    | has 1000+ tests either way, and which machine ran the command is not a
    | reason to publish different guidelines.
    |
    | Boost merges this file over its own with a shallow mergeConfigFrom, so
    | every key omitted here still falls back to the package default.
    |
    */

    'enforce_tests' => true,

];
