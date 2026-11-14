<?php

declare(strict_types=1);

namespace Flytachi\Winter\SDK\S5w\Entity;

use Flytachi\Winter\SDK\S5w\Support\Payload;

/**
 * The file a temporary link leads to — enough to show it in a list and to get the whole
 * {@see File}: `$s5w->files()->get($link->file->id)`.
 *
 * @link https://winterframe.net/packages/sdk-s5w/entities#fileref FileRef
 */
final readonly class FileRef implements \JsonSerializable
{
    public function __construct(
        /** The file's id, as in {@see File::$id}. */
        public string $id,
        public string $name,
    ) {
    }

    /** @internal */
    public static function fromPayload(Payload $p): self
    {
        return new self(id: $p->string('id'), name: $p->string('name'));
    }

    /** @return array<string, mixed> */
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
