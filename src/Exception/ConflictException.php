<?php

declare(strict_types=1);

namespace Flytachi\Winter\SDK\S5w\Exception;

/**
 * 409 — a name is already taken, or a chunk does not continue the upload.
 *
 * @link https://winterframe.net/packages/sdk-s5w/exceptions#apiexception ApiException
 */
class ConflictException extends ApiException
{
}
