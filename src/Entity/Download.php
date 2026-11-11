<?php

declare(strict_types=1);

namespace Flytachi\Winter\SDK\S5w\Entity;

/**
 * What `files()->download()` saved, and how.
 *
 * @link https://winterframe.net/packages/sdk-s5w/entities#download Download
 */
final readonly class Download implements \JsonSerializable
{
    public function __construct(
        /** Where the file now is — the final path, never the `.part`. */
        public string $path,
        /** The file name used for {@see $path} (from the `File`, or from the server). */
        public string $name,
        /** Bytes on disk — the whole file. */
        public int $size,
        /** The type the server gave the file. */
        public string $mime,
        /** An earlier, broken download was continued instead of starting over. */
        public bool $resumed,
        /** The bytes were checked against the file's sha256 (only when a `File` was passed). */
        public bool $verified,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'name' => $this->name,
            'size' => $this->size,
            'mime' => $this->mime,
            'resumed' => $this->resumed,
            'verified' => $this->verified,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
