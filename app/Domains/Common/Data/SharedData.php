<?php

declare(strict_types=1);

namespace App\Domains\Common\Data;

use App\Domains\Identity\Data\UserData;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class SharedData extends Data
{
    public function __construct(
        public ?UserData $user,
    ) {}
}
