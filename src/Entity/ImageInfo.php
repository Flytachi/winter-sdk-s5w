<?php

declare(strict_types=1);

namespace Flytachi\Winter\SDK\S5w\Entity;

use Flytachi\Winter\SDK\S5w\Support\Payload;

/**
 * An image before or after processing.
 *
 * @link https://winterframe.net/packages/sdk-s5w/entities#imageinfo ImageInfo
 */
final readonly class ImageInfo implements \JsonSerializable
{
    public function __construct(
        public int $width,
        public int $height,
        /** Bytes. */
        public int $size,
        public string $mime,
    ) {
    }

    /** @internal */
    public static function fromPayload(Payload $p): self
    {
        return new self(
            width: $p->int('width'),
            height: $p->int('height'),
            size: $p->int('size'),
            mime: $p->string('mime'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'width' => $this->width,
            'height' => $this->height,
            'size' => $this->size,
            'mime' => $this->mime,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
