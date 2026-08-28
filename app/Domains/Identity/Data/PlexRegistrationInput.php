<?php

declare(strict_types=1);

namespace App\Domains\Identity\Data;

/**
 * The submitted half of a Plex registration: untrusted request data that
 * RegisterPlexUser validates. Every property is nullable so a missing field
 * reaches the Validator rather than failing construction, and there is
 * deliberately no email — the address comes from VerifiedPlexIdentity, so this
 * type gives a spoofed one nowhere to travel.
 */
final readonly class PlexRegistrationInput
{
    public function __construct(
        public ?string $name,
        public ?string $password,
        public ?string $passwordConfirmation,
    ) {}
}
