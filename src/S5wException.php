<?php

declare(strict_types=1);

namespace Flytachi\Winter\SDK\S5w;

/**
 * Root of everything the SDK throws. Catch this one to catch them all.
 *
 * ```
 * S5wException
 * ├── Exception\TransportException   the service was not reached (DNS, refused, timeout)
 * ├── Exception\ResponseException    reached, but the answer is not what s5w sends
 * └── Exception\ApiException         s5w answered with an error status
 *     ├── UnauthorizedException      401 — key missing or unknown
 *     ├── ForbiddenException         403 — key disabled, expired, or read-only
 *     ├── NotFoundException          404
 *     ├── ConflictException          409 — name taken, chunk offset mismatch
 *     ├── PayloadTooLargeException   413 — quota exceeded, chunk too big
 *     ├── ValidationException        422 — with per-field errors()
 *     └── ServerException            5xx
 * ```
 *
 * @link https://winterframe.net/packages/sdk-s5w/exceptions#s5wexception S5wException
 */
class S5wException extends \RuntimeException
{
}
