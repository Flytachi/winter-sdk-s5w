<?php

declare(strict_types=1);

namespace Flytachi\Winter\SDK\S5w\Exception;

use Flytachi\Winter\SDK\S5w\S5wException;

/**
 * The request never got an HTTP answer: DNS failure, connection refused, timeout.
 *
 * The one failure that is worth retrying later as it is.
 */
class TransportException extends S5wException
{
    public function isTimeout(): bool
    {
        return $this->getPrevious() instanceof \Flytachi\Winter\Cast\Exception\TimeoutException;
    }
}
