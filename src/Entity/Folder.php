<?php

declare(strict_types=1);

namespace Flytachi\Winter\SDK\S5w\Entity;

use Flytachi\Winter\SDK\S5w\Enum\Retention;
use Flytachi\Winter\SDK\S5w\Support\Payload;

/**
 * A folder: one level, no nesting, addressed by its name (unique in the bucket).
 * Its settings apply to every file inside.
 */
final readonly class Folder
{
    public function __construct(
        public string $name,
        /** Files inside are served at `/o/{bucketId}/{slug}` without a key. */
        public bool $public,
        public Retention $retention,
        public CachePolicy $cache,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {
    }

    /** @internal */
    public static function fromPayload(Payload $p): self
    {
        return new self(
            name: $p->string('name'),
            public: $p->bool('public'),
            retention: $p->enum('retention', Retention::class),
            cache: CachePolicy::fromPayload($p->object('cache')),
            createdAt: $p->time('createdAt'),
            updatedAt: $p->time('updatedAt'),
        );
    }
}
