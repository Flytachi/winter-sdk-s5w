<?php

declare(strict_types=1);

namespace Flytachi\Winter\SDK\S5w\Exception;

/**
 * 5xx — the service failed on its side; worth retrying later.
 */
class ServerException extends ApiException
{
}
