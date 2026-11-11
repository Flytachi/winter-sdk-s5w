<?php

declare(strict_types=1);

namespace Flytachi\Winter\SDK\S5w\Enum;

/**
 * What a temporary link does in a browser. Mirrors the server's `Disposition`.
 *
 * @link https://winterframe.net/packages/sdk-s5w/options-and-enums#disposition Disposition
 */
enum Disposition: int
{
    /** Open in the browser when the type allows it (images, video, PDF…), else download. */
    case INLINE = 0;

    /** Always download (`Content-Disposition: attachment`). */
    case ATTACHMENT = 1;
}
