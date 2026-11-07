<?php

declare(strict_types=1);

namespace Flytachi\Winter\SDK\S5w\Exception;

/**
 * 403 — the key is known and refused: disabled, expired, or read-only where full
 * access is needed. Retrying with the same key never helps.
 */
class ForbiddenException extends ApiException
{
}
