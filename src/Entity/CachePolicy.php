<?php

declare(strict_types=1);

namespace Flytachi\Winter\SDK\S5w\Entity;

use Flytachi\Winter\SDK\S5w\Enum\CacheVisibility;
use Flytachi\Winter\SDK\S5w\Support\Payload;

/**
 * A cache override. Each `null` field is inherited from the level above
 * (folder → bucket → instance defaults).
 */
final readonly class CachePolicy implements \JsonSerializable
{
    public function __construct(
        /** `max-age`, seconds. */
        public ?int $maxAge,
        public ?CacheVisibility $visibility,
    ) {
    }

    /** @internal */
    public static function fromPayload(Payload $p): self
    {
        return new self(
            maxAge: $p->intOrNull('maxAge'),
            visibility: $p->enumOrNull('visibility', CacheVisibility::class),
        );
    }

    /** Whether nothing is overridden here. */
    public function isInherited(): bool
    {
        return $this->maxAge === null && $this->visibility === null;
    }

    /**
     * Plain data: dates as ISO 8601 strings, enums by name.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'maxAge' => $this->maxAge,
            'visibility' => $this->visibility?->name,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
