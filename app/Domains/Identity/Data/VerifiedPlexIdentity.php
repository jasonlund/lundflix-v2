<?php

declare(strict_types=1);

namespace App\Domains\Identity\Data;

use App\Domains\Common\Data\PlexAccount;

/**
 * The server-verified half of a Plex registration: the account behind a claimed
 * PIN, plus that PIN's token. Trusted — it comes from Plex, never from the form.
 */
final readonly class VerifiedPlexIdentity
{
    public function __construct(
        public PlexAccount $account,
        public string $token,
    ) {}
}
