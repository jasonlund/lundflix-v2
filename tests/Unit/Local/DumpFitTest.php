<?php

declare(strict_types=1);

use App\Domains\Local\Database\DumpFit;

it('returns the whole table when the full dump fits under the cap', function (): void {
    // Arrange
    // full dump measures well under the cap, so nothing needs trimming
    $measure = fn (int $n): int => $n * 1_000;

    // Act
    $chosen = DumpFit::largestUnderCap(totalRows: 100, cap: 1_000_000, measure: $measure);

    // Assert
    expect($chosen)->toBe(100);
});

it('shrinks to the largest row count whose dump stays under the cap', function (): void {
    // Arrange
    // each row adds 1_000_000 bytes; a 40_000_000 cap admits exactly the first 40 rows
    $measure = fn (int $n): int => $n * 1_000_000;

    // Act
    $chosen = DumpFit::largestUnderCap(totalRows: 100, cap: 40_000_000, measure: $measure);

    // Assert
    expect($chosen)->toBe(40);
});

it('includes the row count whose dump measures exactly at the cap', function (): void {
    // Arrange
    // measure(50) == 50_000_000 lands on the cap; <= must keep it, never treat it as over
    $measure = fn (int $n): int => $n * 1_000_000;

    // Act
    $chosen = DumpFit::largestUnderCap(totalRows: 100, cap: 50_000_000, measure: $measure);

    // Assert
    expect($chosen)->toBe(50);
});

it('returns zero for an empty table without ever measuring', function (): void {
    // Arrange
    // a spy that fails the test if the policy measures anything for zero rows
    $measured = false;
    $measure = function (int $n) use (&$measured): int {
        $measured = true;

        return 0;
    };

    // Act
    $chosen = DumpFit::largestUnderCap(totalRows: 0, cap: 1_000_000, measure: $measure);

    // Assert
    expect($chosen)->toBe(0)
        ->and($measured)->toBeFalse();
});
