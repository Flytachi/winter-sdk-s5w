<?php

declare(strict_types=1);

namespace Flytachi\Winter\SDK\S5w\Exception;

use Flytachi\Winter\SDK\S5w\S5wException;

/**
 * `files()->contents()` refused a file larger than its limit — before downloading it when the
 * size is known, otherwise as soon as the bytes passed the limit. Nothing is kept in memory.
 *
 * ```
 * try {
 *     $bytes = $s5w->files()->contents($id);
 * } catch (ContentTooLargeException $e) {
 *     $s5w->files()->download($id, '/var/data/');   // large: to disk instead
 * }
 * ```
 *
 * @link https://winterframe.net/packages/sdk-s5w/exceptions#contenttoolargeexception ContentTooLargeException
 */
class ContentTooLargeException extends S5wException
{
    public function __construct(
        private readonly string $fileId,
        private readonly int $limit,
        private readonly ?int $size = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf(
                'S5W: file %s%s is over the contents() limit of %d bytes — raise maxSize or use download()',
                $fileId,
                $size === null ? '' : " ({$size} bytes)",
                $limit,
            ),
            0,
            $previous,
        );
    }

    /** @link https://winterframe.net/packages/sdk-s5w/exceptions#contenttoolargeexception ContentTooLargeException */
    public function fileId(): string
    {
        return $this->fileId;
    }

    /**
     * The limit that was exceeded, in bytes.
     *
     * @link https://winterframe.net/packages/sdk-s5w/exceptions#contenttoolargeexception ContentTooLargeException
     */
    public function limit(): int
    {
        return $this->limit;
    }

    /**
     * The file's size when it was known (from the `File` passed in); null when the download
     * was stopped on the way.
     *
     * @link https://winterframe.net/packages/sdk-s5w/exceptions#contenttoolargeexception ContentTooLargeException
     */
    public function size(): ?int
    {
        return $this->size;
    }
}
