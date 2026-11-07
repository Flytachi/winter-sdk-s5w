<?php

declare(strict_types=1);

namespace Flytachi\Winter\SDK\S5w\Entity;

use Flytachi\Winter\SDK\S5w\Support\Payload;

/**
 * What the server did with image options at upload.
 *
 * Not applied is not an error; {@see $reason} says why: `no options`,
 * `animated` (animated images are kept as they are), `nothing to do`
 * (already within the limits and the format), `output is larger than source`.
 *
 * @link https://winterframe.net/packages/sdk-s5w/entities#processing Processing
 */
final readonly class Processing implements \JsonSerializable
{
    /**
     * @param list<string> $operations e.g. `["resize:1600x1067", "encode:webp@82"]`
     */
    public function __construct(
        public bool $applied,
        /** Why nothing was done; null when applied. */
        public ?string $reason,
        public array $operations,
        public ?ImageInfo $source,
        public ?ImageInfo $result,
    ) {
    }

    /** @internal */
    public static function fromPayload(Payload $p): self
    {
        $source = $p->objectOrNull('source');
        $result = $p->objectOrNull('result');

        return new self(
            applied: $p->bool('applied'),
            reason: $p->stringOrNull('reason'),
            operations: $p->strings('operations'),
            source: $source === null ? null : ImageInfo::fromPayload($source),
            result: $result === null ? null : ImageInfo::fromPayload($result),
        );
    }

    /**
     * Bytes saved by processing; negative if the result grew. 0 when not applied.
     *
     * @link https://winterframe.net/packages/sdk-s5w/entities#processing Processing
     */
    public function saved(): int
    {
        return $this->source !== null && $this->result !== null
            ? $this->source->size - $this->result->size
            : 0;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'applied' => $this->applied,
            'reason' => $this->reason,
            'operations' => $this->operations,
            'source' => $this->source?->toArray(),
            'result' => $this->result?->toArray(),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
