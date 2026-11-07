<?php

declare(strict_types=1);

namespace Flytachi\Winter\SDK\S5w\Support;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * Fills `{placeholder}`s from the context before handing the message on.
 *
 * PSR-3 only says a logger *may* interpolate, and many don't (winter-logger, a bare
 * Monolog without PsrLogMessageProcessor) — so the SDK does it itself and the line
 * reads right whatever logger is behind. The context is still passed along for
 * loggers that index it.
 *
 * @internal
 * @link https://winterframe.net/packages/sdk-s5w/http-and-logging#filling-in-values Filling in values
 */
final class InterpolatingLogger extends AbstractLogger
{
    public function __construct(private readonly LoggerInterface $inner)
    {
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->inner->log($level, self::interpolate((string) $message, $context), $context);
    }

    /** @param array<string, mixed> $context */
    public static function interpolate(string $message, array $context): string
    {
        if (!str_contains($message, '{')) {
            return $message;
        }

        $replace = [];
        foreach ($context as $key => $value) {
            $replace['{' . $key . '}'] = match (true) {
                $value === null => 'null',
                is_bool($value) => $value ? 'true' : 'false',
                is_scalar($value), $value instanceof \Stringable => (string) $value,
                $value instanceof \DateTimeInterface => $value->format(\DATE_ATOM),
                default => get_debug_type($value),
            };
        }

        return strtr($message, $replace);
    }
}
