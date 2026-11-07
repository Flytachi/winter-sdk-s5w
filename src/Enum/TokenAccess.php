<?php

declare(strict_types=1);

namespace Flytachi\Winter\SDK\S5w\Enum;

/**
 * What a key opens. Mirrors the server's `TokenAccess`.
 *
 * @link https://winterframe.net/packages/sdk-s5w/options-and-enums#tokenaccess TokenAccess
 */
enum TokenAccess: int
{
    /** Delivery only: `/p/{slug}` and `GET /v1/check`. Small enough to ship inside a client app. */
    case BASIC = 1;

    /** All of `/v1`: files, folders, uploads, temporary links. */
    case FULL = 2;

    /**
     * Whether this key may call the management API (`/v1/files`, `/v1/folders`, …).
     *
     * @link https://winterframe.net/packages/sdk-s5w/options-and-enums#tokenaccess TokenAccess
     */
    public function isFull(): bool
    {
        return $this === self::FULL;
    }
}
