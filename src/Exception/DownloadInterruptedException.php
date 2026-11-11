<?php

declare(strict_types=1);

namespace Flytachi\Winter\SDK\S5w\Exception;

use Flytachi\Winter\SDK\S5w\S5wException;

/**
 * `files()->download()` stopped on a network failure after its attempts ran out.
 *
 * What arrived is kept in the `.part` file next to the target, so the same call later
 * continues from there instead of starting over:
 * ```
 * catch (DownloadInterruptedException $e) {
 *     $e->offset();                                         // bytes already on disk
 *     $s5w->files()->download($id, '/var/data/');           // later: picks up the .part
 * }
 * ```
 * The cause is in {@see getPrevious()}.
 *
 * @link https://winterframe.net/packages/sdk-s5w/exceptions#downloadinterruptedexception DownloadInterruptedException
 */
class DownloadInterruptedException extends S5wException
{
    public function __construct(
        private readonly string $fileId,
        private readonly string $partPath,
        private readonly int $offset,
        private readonly int $size,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf(
                'S5W: download of %s interrupted at %d of %d bytes (kept in %s): %s',
                $fileId,
                $offset,
                $size,
                $partPath,
                $previous?->getMessage() ?? 'unknown failure',
            ),
            0,
            $previous,
        );
    }

    /** @link https://winterframe.net/packages/sdk-s5w/exceptions#downloadinterruptedexception DownloadInterruptedException */
    public function fileId(): string
    {
        return $this->fileId;
    }

    /**
     * The `.part` file holding what arrived; the next `download()` to the same target uses it.
     *
     * @link https://winterframe.net/packages/sdk-s5w/exceptions#downloadinterruptedexception Exceptions
     */
    public function partPath(): string
    {
        return $this->partPath;
    }

    /**
     * Bytes already on disk.
     *
     * @link https://winterframe.net/packages/sdk-s5w/exceptions#downloadinterruptedexception Exceptions
     */
    public function offset(): int
    {
        return $this->offset;
    }

    /**
     * The whole file's size.
     *
     * @link https://winterframe.net/packages/sdk-s5w/exceptions#downloadinterruptedexception Exceptions
     */
    public function size(): int
    {
        return $this->size;
    }
}
