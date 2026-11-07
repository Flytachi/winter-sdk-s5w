<?php

declare(strict_types=1);

namespace Flytachi\Winter\SDK\S5w\Exception;

/**
 * 404 — no such file, folder, link or upload in this bucket. "Not yours" answers the same way.
 */
class NotFoundException extends ApiException
{
}
