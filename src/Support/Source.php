<?php

declare(strict_types=1);

namespace Flytachi\Winter\SDK\S5w\Support;

use Flytachi\Winter\SDK\S5w\S5wException;

/**
 * Bytes to upload, from a file on disk or from memory, read piece by piece:
 * a file is never loaded whole, only one chunk at a time.
 *
 * @internal
 * @link https://winterframe.net/packages/sdk-s5w/upload-pipeline#the-byte-source The byte source
 */
final class Source
{
    /** @var resource|null */
    private $handle = null;

    private function __construct(
        public readonly string $name,
        public readonly int $size,
        private readonly ?string $path,
        private readonly ?string $content,
    ) {
    }

    public static function path(string|\SplFileInfo $file): self
    {
        $path = $file instanceof \SplFileInfo ? $file->getPathname() : $file;
        if (!is_file($path) || !is_readable($path)) {
            throw new S5wException("S5W: cannot read the file '{$path}'");
        }
        clearstatcache(true, $path);

        return new self(basename($path), (int) filesize($path), $path, null);
    }

    public static function content(string $content, string $name): self
    {
        return new self($name, strlen($content), null, $content);
    }

    public function pathname(): ?string
    {
        return $this->path;
    }

    /** SHA-256 of the whole content, lowercase hex. Streams a file, never loads it. */
    public function sha256(): string
    {
        $hash = $this->path !== null ? hash_file('sha256', $this->path) : hash('sha256', (string) $this->content);
        if ($hash === false) {
            throw new S5wException("S5W: cannot read the file '{$this->path}'");
        }

        return $hash;
    }

    /** Up to `$length` bytes from `$offset`. */
    public function read(int $offset, int $length): string
    {
        if ($this->content !== null) {
            return substr($this->content, $offset, $length);
        }

        $this->handle ??= @fopen((string) $this->path, 'rb')
            ?: throw new S5wException("S5W: cannot open the file '{$this->path}'");

        if (fseek($this->handle, $offset) !== 0) {
            throw new S5wException("S5W: cannot seek to {$offset} in '{$this->path}'");
        }

        $bytes = '';
        while (strlen($bytes) < $length && !feof($this->handle)) {
            $piece = fread($this->handle, $length - strlen($bytes));
            if ($piece === false) {
                throw new S5wException("S5W: cannot read '{$this->path}' at {$offset}");
            }
            $bytes .= $piece;
        }

        return $bytes;
    }

    public function __destruct()
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }
}
