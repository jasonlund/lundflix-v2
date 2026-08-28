<?php

declare(strict_types=1);

namespace App\Domains\Identity\Support;

use App\Domains\Common\Data\PlexAccount;
use App\Domains\Identity\Data\VerifiedPlexIdentity;

/**
 * The Plex sign-in flow spans three requests and hands state between them
 * through the session: /auth/plex stashes the PIN, the callback trades it for a
 * verified identity, /register renders and then consumes that identity. Each
 * key is written by one controller and read by another, and a mistyped literal
 * fails silently — the read returns null and the guest is simply bounced to
 * /login — so the keys and their shapes live here, at neither end.
 *
 * The verified identity is marshalled to a plain payload on the way in and
 * hydrated on the way out because `config('session.serialization')` is `json`:
 * an object handed to the session is JSON-encoded when the handler writes it
 * and decodes back as a bare array on the next request, so stashing the DTO
 * itself would hand /register an array and bounce every guest to /login.
 * Marshalling on the way in leaves the payload as the only shape the key ever
 * holds, so the read has a single form to hydrate.
 */
final class PlexSession
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

    public static function stashVerifiedIdentity(VerifiedPlexIdentity $identity): void
    {
        session([self::VERIFIED_IDENTITY => [
            'account' => [
                'id' => $identity->account->id,
                'uuid' => $identity->account->uuid,
                'username' => $identity->account->username,
                'email' => $identity->account->email,
                'thumb' => $identity->account->thumb,
            ],
            'token' => $identity->token,
        ]]);
    }

    public static function verifiedIdentity(): ?VerifiedPlexIdentity
    {
        $stash = session(self::VERIFIED_IDENTITY);

        return is_array($stash) ? self::hydrate($stash) : null;
    }

    public static function forgetVerifiedIdentity(): void
    {
        session()->forget(self::VERIFIED_IDENTITY);
    }

    /**
     * The mirror of stashVerifiedIdentity(): the same two keys, then the same
     * five account fields in the same order.
     *
     * A payload it cannot rebuild an identity from — no account, no token, a
     * field the stash never carried, or a value of the wrong type — reads as no
     * identity at all, so a stash truncated by a deploy or half-written bounces
     * the guest to /login instead of reaching the constructor as a TypeError.
     *
     * @param  array<array-key, mixed>  $stash
     */
    private static function hydrate(array $stash): ?VerifiedPlexIdentity
    {
        $account = $stash['account'] ?? null;
        $token = $stash['token'] ?? null;

        if (! is_array($account) || ! is_string($token)) {
            return null;
        }

        foreach (['id', 'uuid', 'username', 'email', 'thumb'] as $field) {
            if (! array_key_exists($field, $account)) {
                return null;
            }
        }

        return new VerifiedPlexIdentity(
            new PlexAccount(
                id: is_int($account['id']) ? $account['id'] : null,
                uuid: is_string($account['uuid']) ? $account['uuid'] : null,
                username: is_string($account['username']) ? $account['username'] : null,
                email: is_string($account['email']) ? $account['email'] : null,
                thumb: is_string($account['thumb']) ? $account['thumb'] : null,
            ),
            token: $token,
        );
    }
}
