<?php

declare(strict_types=1);

namespace Flytachi\Winter\SDK\S5w\Model;

use Flytachi\Winter\SDK\S5w\Support\Payload;

/**
 * The bucket a key belongs to.
 */
final readonly class BucketRef
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
}
