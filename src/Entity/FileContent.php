<?php

declare(strict_types=1);

namespace Flytachi\Winter\SDK\S5w\Entity;

use Flytachi\Winter\SDK\S5w\Support\Payload;

/**
 * The bytes behind a file. Two files with the same content share one of these
 * on the server (deduplication by SHA-256).
 *
 * @link https://winterframe.net/packages/sdk-s5w/entities#filecontent FileContent
 */
final readonly class FileContent implements \JsonSerializable
{
    public function __construct(
        /** Bytes. */
        public int $size,
        /** Detected from the content by the server, not taken from the client. */
        public string $mime,
        public string $extension,
        /** SHA-256, lowercase hex. */
        public string $hash,
    ) {
    }

    /** @internal */
    public static function fromPayload(Payload $p): self
    {
        return new self(
            size: $p->int('size'),
            mime: $p->string('mime'),
            extension: $p->string('extension'),
            hash: $p->string('hash'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'size' => $this->size,
            'mime' => $this->mime,
            'extension' => $this->extension,
            'hash' => $this->hash,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
