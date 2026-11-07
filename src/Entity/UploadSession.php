<?php

declare(strict_types=1);

namespace Flytachi\Winter\SDK\S5w\Entity;

use Flytachi\Winter\SDK\S5w\Support\Payload;

/**
 * A chunked upload in progress — the "receipt" every `uploads()` call takes and returns.
 *
 * Immutable: each call answers with a new one carrying the server's current
 * {@see $offset}. Nothing is in the bucket until the session is completed.
 *
 * @link https://winterframe.net/packages/sdk-s5w/entities#uploadsession UploadSession
 */
final readonly class UploadSession implements \JsonSerializable
{
    public function __construct(
        /** UUID; empty when {@see isDeduplicated()} — there is no session then. */
        public string $id,
        public string $name,
        /** Declared total, bytes. Binding: the file is refused if the total differs. */
        public int $size,
        /** How many bytes the server holds — where the next chunk starts. */
        public int $offset,
        /** Chunk size the server asks for; 0 when deduplicated. */
        public int $chunkSize,
        /** When the session is swept if no chunk arrives; null when deduplicated. */
        public ?\DateTimeImmutable $expiresAt,
        /** The finished file, when the content was already in the bucket. */
        public ?File $file,
    ) {
    }

    /** @internal */
    public static function fromPayload(Payload $p): self
    {
        $file = $p->objectOrNull('file');

        return new self(
            id: $p->string('id'),
            name: $p->string('name'),
            size: $p->int('size'),
            offset: $p->int('offset'),
            chunkSize: $p->int('chunkSize'),
            expiresAt: $p->timeOrNull('expiresAt'),
            file: $file === null ? null : File::fromPayload($file),
        );
    }

    /**
     * The content was already in the bucket: the file is created, nothing to send.
     *
     * @link https://winterframe.net/packages/sdk-s5w/entities#uploadsession UploadSession
     */
    public function isDeduplicated(): bool
    {
        return $this->file !== null;
    }

    /**
     * Every byte is on the server — time to complete.
     *
     * @link https://winterframe.net/packages/sdk-s5w/entities#uploadsession UploadSession
     */
    public function isComplete(): bool
    {
        return $this->offset >= $this->size;
    }

    /**
     * Bytes still to send.
     *
     * @link https://winterframe.net/packages/sdk-s5w/entities#uploadsession UploadSession
     */
    public function remaining(): int
    {
        return max(0, $this->size - $this->offset);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'size' => $this->size,
            'offset' => $this->offset,
            'chunkSize' => $this->chunkSize,
            'expiresAt' => $this->expiresAt?->format(\DATE_ATOM),
            'file' => $this->file?->toArray(),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
