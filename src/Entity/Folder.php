<?php

declare(strict_types=1);

namespace Flytachi\Winter\SDK\S5w\Entity;

use Flytachi\Winter\SDK\S5w\Enum\Retention;
use Flytachi\Winter\SDK\S5w\Support\Payload;

/**
 * A folder: one level, no nesting, addressed by its name (unique in the bucket).
 * Its settings apply to every file inside.
 */
final readonly class Folder implements \JsonSerializable
{
    /**
     * "No folder" — the bucket root — where a folder is expected as a filter:
     * `files()->list(folder: Folder::ROOT)` lists only the files outside any folder.
     * `/` can never be a real folder's name, so the two never clash.
     */
    public const string ROOT = '/';

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

    /**
     * Plain data: dates as ISO 8601 strings, enums by name.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'public' => $this->public,
            'retention' => $this->retention->name,
            'cache' => $this->cache->toArray(),
            'createdAt' => $this->createdAt->format(\DATE_ATOM),
            'updatedAt' => $this->updatedAt->format(\DATE_ATOM),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
