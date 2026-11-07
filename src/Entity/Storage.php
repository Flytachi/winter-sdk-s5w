<?php

declare(strict_types=1);

namespace Flytachi\Winter\SDK\S5w\Entity;

use Flytachi\Winter\SDK\S5w\Support\Payload;

/**
 * Bucket space, in bytes.
 */
final readonly class Storage implements \JsonSerializable
{
    public function __construct(
        public int $quota,
        public int $used,
        public int $free,
    ) {
    }

    /** @internal */
    public static function fromPayload(Payload $p): self
    {
        return new self(quota: $p->int('quota'), used: $p->int('used'), free: $p->int('free'));
    }

    /** Whether `$bytes` more would still fit. */
    public function fits(int $bytes): bool
    {
        return $bytes <= $this->free;
    }

    /** Used share of the quota, 0.0 … 1.0. */
    public function usage(): float
    {
        return $this->quota > 0 ? min(1.0, $this->used / $this->quota) : 0.0;
    }

    /**
     * Plain data: dates as ISO 8601 strings, enums by name.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'quota' => $this->quota,
            'used' => $this->used,
            'free' => $this->free,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
