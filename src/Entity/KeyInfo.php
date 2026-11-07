<?php

declare(strict_types=1);

namespace Flytachi\Winter\SDK\S5w\Entity;

use Flytachi\Winter\SDK\S5w\Enum\TokenAccess;
use Flytachi\Winter\SDK\S5w\Support\Payload;

/**
 * What `GET /v1/check` says about the key: its bucket, its access, when it
 * expires and how much room is left.
 */
final readonly class KeyInfo implements \JsonSerializable
{
    public function __construct(
        public BucketRef $bucket,
        public TokenAccess $access,
        /** null — the key never expires. */
        public ?\DateTimeImmutable $expiresAt,
        public Storage $storage,
    ) {
    }

    /** @internal */
    public static function fromPayload(Payload $p): self
    {
        return new self(
            bucket: BucketRef::fromPayload($p->object('bucket')),
            access: $p->enum('access', TokenAccess::class),
            expiresAt: $p->timeOrNull('expiresAt'),
            storage: Storage::fromPayload($p->object('bytes')),
        );
    }

    /** Whether the key will be past its expiry at `$at` (default: now). */
    public function expiresBefore(\DateTimeInterface $at = new \DateTimeImmutable()): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= $at;
    }

    /**
     * Plain data: dates as ISO 8601 strings, enums by name.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'bucket' => $this->bucket->toArray(),
            'access' => $this->access->name,
            'expiresAt' => $this->expiresAt?->format(\DATE_ATOM),
            'storage' => $this->storage->toArray(),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
