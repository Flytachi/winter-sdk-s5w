<?php

declare(strict_types=1);

namespace Flytachi\Winter\SDK\S5w\Exception;

use Flytachi\Winter\SDK\S5w\S5wException;

/**
 * s5w answered with an error status. The body was `{ "code", "message", "errors"? }`.
 *
 * Branch on the class (or {@see status()}), never on the message: the message is
 * for logs. {@see from()} picks the subclass by status.
 */
class ApiException extends S5wException
{
    /**
     * @param array<string, list<string>> $errors
     */
    final public function __construct(
        private readonly int $status,
        string $message,
        private readonly array $errors = [],
        private readonly string $method = '',
        private readonly string $path = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    /**
     * @param array<string, list<string>> $errors
     */
    public static function from(
        int $status,
        string $message,
        array $errors = [],
        string $method = '',
        string $path = '',
        ?\Throwable $previous = null,
    ): static {
        $class = match (true) {
            $status === 401 => UnauthorizedException::class,
            $status === 403 => ForbiddenException::class,
            $status === 404 => NotFoundException::class,
            $status === 409 => ConflictException::class,
            $status === 413 => PayloadTooLargeException::class,
            $status === 422 => ValidationException::class,
            $status >= 500 => ServerException::class,
            default => static::class,
        };

        $shown = rawurldecode($path);
        $text = $path !== '' ? "S5W {$shown}: {$status} {$message}" : "S5W: {$status} {$message}";

        return new $class($status, $text, $errors, $method, $path, $previous);
    }

    /** HTTP status code. */
    public function status(): int
    {
        return $this->status;
    }

    /**
     * Per-field validation messages; empty unless this is a 422.
     *
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /** The request that failed: HTTP method. */
    public function method(): string
    {
        return $this->method;
    }

    /** The request that failed: path relative to the base URL. */
    public function path(): string
    {
        return $this->path;
    }
}
