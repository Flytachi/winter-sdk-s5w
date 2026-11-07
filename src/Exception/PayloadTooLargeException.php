<?php

declare(strict_types=1);

namespace Flytachi\Winter\SDK\S5w\Exception;

/**
 * 413 — over the bucket quota, or a chunk over the limit.
 */
class PayloadTooLargeException extends ApiException
{
}
