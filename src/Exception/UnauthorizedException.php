<?php

declare(strict_types=1);

namespace Flytachi\Winter\SDK\S5w\Exception;

/**
 * 401 — no key, or the service does not know it. A different key fixes it.
 *
 * @link https://winterframe.net/packages/sdk-s5w/exceptions#apiexception ApiException
 */
class UnauthorizedException extends ApiException
{
}
