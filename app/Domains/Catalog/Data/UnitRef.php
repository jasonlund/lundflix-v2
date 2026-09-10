<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Data;

use App\Domains\Catalog\Enums\UnitKind;

final readonly class UnitRef
{
    private function __construct(
        private UnitKind $kind,
        private int $id,
    ) {}

    public static function movie(int $id): self
    {
        return new self(UnitKind::Movie, $id);
    }

    public static function episode(int $id): self
    {
        return new self(UnitKind::Episode, $id);
    }

    public function id(): int
    {
        return $this->id;
    }

    public function kind(): UnitKind
    {
        return $this->kind;
    }
}
