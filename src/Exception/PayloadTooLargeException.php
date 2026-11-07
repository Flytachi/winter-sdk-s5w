<?php

declare(strict_types=1);

namespace Flytachi\Winter\SDK\S5w\Exception;

/**
 * 413 — over the bucket quota, or a chunk over the limit.
 *
 * @link https://winterframe.net/packages/sdk-s5w/exceptions#apiexception ApiException
 */
class PayloadTooLargeException extends ApiException
{
}
