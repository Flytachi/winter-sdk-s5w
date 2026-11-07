<?php

declare(strict_types=1);

namespace Flytachi\Winter\SDK\S5w\Enum;

/**
 * What a file list is ordered by.
 */
enum FileSort: string
{
    /** Upload time — the server's default. */
    case CREATED = 'created';
    case NAME = 'name';
    /** MIME type, then name. */
    case TYPE = 'type';
    /** Content size, then name. */
    case SIZE = 'size';
}
