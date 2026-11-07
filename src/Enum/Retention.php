<?php

declare(strict_types=1);

namespace Flytachi\Winter\SDK\S5w\Enum;

/**
 * How long files live in a folder, counted from each file's creation.
 * Mirrors the server's `Retention`; the sweep runs every five minutes.
 */
enum Retention: int
{
    case NONE = 0;
    case DAY = 1;
    case WEEK = 2;
    case MONTH = 3;
    case QUARTER = 4;
    case HALF_YEAR = 5;
    case YEAR = 6;

    /** null — files are kept until deleted. */
    public function interval(): ?\DateInterval
    {
        return match ($this) {
            self::NONE => null,
            self::DAY => new \DateInterval('P1D'),
            self::WEEK => new \DateInterval('P7D'),
            self::MONTH => new \DateInterval('P1M'),
            self::QUARTER => new \DateInterval('P3M'),
            self::HALF_YEAR => new \DateInterval('P6M'),
            self::YEAR => new \DateInterval('P1Y'),
        };
    }
}
