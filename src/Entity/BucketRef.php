<?php

declare(strict_types=1);

namespace Flytachi\Winter\SDK\S5w\Entity;

use Flytachi\Winter\SDK\S5w\Support\Payload;

/**
 * The bucket a key belongs to.
 *
 * @link https://winterframe.net/packages/sdk-s5w/entities#bucketref BucketRef
 */
final readonly class BucketRef implements \JsonSerializable
{
    public function __construct(
        /** UUID; the `{bucketId}` of public URLs `/o/{bucketId}/{slug}`. */
        public string $id,
        public string $name,
    ) {
    }

    /** @internal */
    public static function fromPayload(Payload $p): self
    {
        return new self(id: $p->string('id'), name: $p->string('name'));
    }

    /**
     * Plain data: dates as ISO 8601 strings, enums by name.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
