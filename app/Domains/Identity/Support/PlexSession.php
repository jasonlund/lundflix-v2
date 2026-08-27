<?php

declare(strict_types=1);

namespace App\Domains\Identity\Support;

/**
 * The Plex sign-in flow spans three requests and hands state between them
 * through the session: /auth/plex stashes the PIN, the callback trades it for a
 * verified identity, /register renders and then consumes that identity. Each
 * key is written by one controller and read by another, and a mistyped literal
 * fails silently — the read returns null and the guest is simply bounced to
 * /login — so the keys and their shapes live here, at neither end.
 */
final readonly class PlexSession
{
    private const string PIN_ID = 'plex_pin_id';

    private const string VERIFIED_IDENTITY = 'plex_registration';

    /**
     * Plex can answer the PIN request without an id; stashing that null is what
     * lets the callback report the miss rather than failing the redirect here.
     */
    public static function rememberPin(int|string|null $pinId): void
    {
        session([self::PIN_ID => $pinId]);
    }

    public static function pullPinId(): ?int
    {
        $pinId = session()->pull(self::PIN_ID);

        return $pinId === null ? null : (int) $pinId;
    }

    /**
     * @param  array{id: int|null, uuid: string|null, username: string|null, email: string|null, thumb: string|null, token: string}  $identity  the Plex account behind a claimed PIN, plus that PIN's token
     */
    public static function stashVerifiedIdentity(array $identity): void
    {
        session([self::VERIFIED_IDENTITY => $identity]);
    }

    /**
     * @return array{id: int|null, uuid: string|null, username: string|null, email: string|null, thumb: string|null, token: string}|null
     */
    public static function verifiedIdentity(): ?array
    {
        return session(self::VERIFIED_IDENTITY);
    }

    public static function forgetVerifiedIdentity(): void
    {
        session()->forget(self::VERIFIED_IDENTITY);
    }
}
