<?php

declare(strict_types=1);

namespace Flytachi\Winter\SDK\S5w\Exception;

/**
 * 409 — a name is already taken, or a chunk does not continue the upload.
 */
class ConflictException extends ApiException
{
}
