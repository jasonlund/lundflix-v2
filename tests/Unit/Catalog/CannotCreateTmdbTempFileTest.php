<?php

declare(strict_types=1);

use App\Domains\Catalog\Exceptions\CannotCreateTmdbTempFile;

it('builds a throwable naming the temp directory it could not write to', function (): void {
    $exception = CannotCreateTmdbTempFile::inTempDir('/tmp');

    expect($exception)
        ->toBeInstanceOf(CannotCreateTmdbTempFile::class)
        ->and($exception)->toBeInstanceOf(Throwable::class)
        ->and($exception->getMessage())->toContain('/tmp');
});
