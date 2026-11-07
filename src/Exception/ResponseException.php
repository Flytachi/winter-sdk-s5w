<?php

declare(strict_types=1);

namespace Flytachi\Winter\SDK\S5w\Exception;

use Flytachi\Winter\SDK\S5w\S5wException;

/**
 * An answer came back, but not in a shape s5w sends: not JSON, a missing field,
 * a wrong type. Usually the base URL points at something that is not s5w (a proxy
 * error page), or the server and the SDK versions disagree.
 *
 * @link https://winterframe.net/packages/sdk-s5w/exceptions#responseexception ResponseException
 */
class ResponseException extends S5wException
{
}
