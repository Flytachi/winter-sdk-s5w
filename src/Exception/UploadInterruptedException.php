<?php

declare(strict_types=1);

namespace Flytachi\Winter\SDK\S5w\Exception;

use Flytachi\Winter\SDK\S5w\S5wException;

/**
 * A chunked upload stopped on a network or server failure after its retries ran out.
 *
 * The session is left open on the server (for 24 h after its last chunk), so the
 * upload can be continued where it stopped:
 * ```
 * catch (UploadInterruptedException $e) {
 *     $file = $s5w->uploads()->resume($e->sessionId(), $path);
 * }
 * ```
 * The cause is in {@see getPrevious()}.
 *
 * @link https://winterframe.net/packages/sdk-s5w/exceptions#uploadinterruptedexception UploadInterruptedException
 */
class UploadInterruptedException extends S5wException
{
    public function __construct(
        private readonly string $sessionId,
        private readonly int $offset,
        private readonly int $size,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf(
                'S5W: upload %s interrupted at %d of %d bytes: %s',
                $sessionId,
                $offset,
                $size,
                $previous?->getMessage() ?? 'unknown failure',
            ),
            0,
            $previous,
        );
    }

    /**
     * Pass it to `uploads()->resume()`.
     *
     * @link https://winterframe.net/packages/sdk-s5w/exceptions#uploadinterruptedexception UploadInterruptedException
     */
    public function sessionId(): string
    {
        return $this->sessionId;
    }

    /**
     * Bytes the server holds, as far as the SDK knows.
     *
     * @link https://winterframe.net/packages/sdk-s5w/exceptions#uploadinterruptedexception UploadInterruptedException
     */
    public function offset(): int
    {
        return $this->offset;
    }

    /**
     * @link https://winterframe.net/packages/sdk-s5w/exceptions#uploadinterruptedexception UploadInterruptedException
     */
    public function size(): int
    {
        return $this->size;
    }
}
