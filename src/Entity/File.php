<?php

declare(strict_types=1);

namespace Flytachi\Winter\SDK\S5w\Entity;

use Flytachi\Winter\SDK\S5w\Support\Payload;

/**
 * A file in the bucket, addressed by its {@see $id}.
 */
final readonly class File implements \JsonSerializable
{
    /** What every file id looks like: 16 characters of `A-Za-z0-9_-`. */
    public const string ID_PATTERN = '/^[A-Za-z0-9_-]{16}$/';

    public function __construct(
        /**
         * 16 characters of `A-Za-z0-9-_` (the server calls it a slug). How the file is
         * addressed everywhere, delivery URLs included; safe to store, log and share.
         */
        public string $id,
        /** Unique inside its folder; a clash on upload becomes `name (1).ext`. */
        public string $name,
        /** null — the bucket root. */
        public ?string $folder,
        public FileContent $content,
        /** Served at {@see $publicUrl} without a key; follows the folder's flag. */
        public bool $public,
        /** The bucket already held these bytes: no disk was spent on this upload. */
        public bool $deduplicated,
        /** Only on the answer to an upload; null when reading a file later. */
        public ?Processing $processed,
        /** When the folder's retention deletes it; null — kept until deleted. */
        public ?\DateTimeImmutable $expiresAt,
        /** `/p/{id}` — needs a key (either kind) in the `Authorization` header. */
        public string $privateUrl,
        /** `/o/{bucketId}/{id}` — no key; null while the file is not public. */
        public ?string $publicUrl,
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * Whether `$id` has the shape of a file id. Says nothing about whether such a
     * file exists — handy to reject a malformed id from a URL before any request.
     */
    public static function isValidId(string $id): bool
    {
        return preg_match(self::ID_PATTERN, $id) === 1;
    }

    /** @internal */
    public static function fromPayload(Payload $p): self
    {
        $processed = $p->objectOrNull('processed');

        return new self(
            id: $p->string('id'),
            name: $p->string('name'),
            folder: $p->stringOrNull('folder'),
            content: FileContent::fromPayload($p->object('content')),
            public: $p->bool('public'),
            deduplicated: $p->bool('deduplicated'),
            processed: $processed === null ? null : Processing::fromPayload($processed),
            expiresAt: $p->timeOrNull('expiresAt'),
            privateUrl: $p->string('privateUrl'),
            publicUrl: $p->stringOrNull('publicUrl'),
            createdAt: $p->time('createdAt'),
            updatedAt: $p->time('updatedAt'),
        );
    }

    /**
     * Plain data: dates as ISO 8601 strings.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'folder' => $this->folder,
            'content' => $this->content->toArray(),
            'public' => $this->public,
            'deduplicated' => $this->deduplicated,
            'processed' => $this->processed?->toArray(),
            'expiresAt' => $this->expiresAt?->format(\DATE_ATOM),
            'privateUrl' => $this->privateUrl,
            'publicUrl' => $this->publicUrl,
            'createdAt' => $this->createdAt->format(\DATE_ATOM),
            'updatedAt' => $this->updatedAt->format(\DATE_ATOM),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
