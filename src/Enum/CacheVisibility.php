<?php

declare(strict_types=1);

namespace Flytachi\Winter\SDK\S5w\Enum;

/**
 * Who may cache a delivered file — the directive of its `Cache-Control`.
 * Mirrors the server's `CacheVisibility`.
 */
enum CacheVisibility: int
{
    /** `public` — browsers and CDNs may store it. */
    case SHARED = 1;

    /** `private` — the browser may, shared caches may not. */
    case PRIVATE = 2;

    /** `no-store` — nobody stores it. */
    case NO_STORE = 3;

    public function directive(): string
    {
        return match ($this) {
            self::SHARED => 'public',
            self::PRIVATE => 'private',
            self::NO_STORE => 'no-store',
        };
    }
}
