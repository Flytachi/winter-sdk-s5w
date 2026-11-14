<?php

declare(strict_types=1);

namespace Flytachi\Winter\SDK\S5w\Entity;

use Flytachi\Winter\SDK\S5w\Enum\Disposition;
use Flytachi\Winter\SDK\S5w\Support\Payload;

/**
 * A temporary link `/t/…` to a file: opens without a key until it expires.
 *
 * Two kinds:
 * - **signed** ({@see isTracked()} false): not stored by the server, `id` is null, cannot be
 *   revoked on its own — only all links of the bucket at once;
 * - **tracked**: issued with `maxDownloads` or `revocable`; stored, listed, revocable on its own,
 *   keeps a `note`. Counts downloads only with `maxDownloads`.
 *
 * A snapshot: `downloads` and `revoked` are as the server said when it answered.
 *
 * @link https://winterframe.net/packages/sdk-s5w/entities#link Link
 */
final readonly class Link implements \JsonSerializable
{
    public function __construct(
        /** null — a signed link. */
        public ?int $id,
        /** The address to hand out: `https://…/t/…`. */
        public string $url,
        /** The file the link leads to; null only from an S5W older than 1.2.0, which does not send it. */
        public ?FileRef $file,
        public \DateTimeImmutable $expiresAt,
        /** Can be revoked on its own. */
        public bool $revocable,
        /** null — no download limit. */
        public ?int $maxDownloads,
        /** Downloads so far — counted only with {@see $maxDownloads}; otherwise always 0. */
        public int $downloads,
        public bool $revoked,
        public Disposition $disposition,
        /** Empty for a signed link. */
        public string $note,
    ) {
    }

    /** @internal */
    public static function fromPayload(Payload $p): self
    {
        $file = $p->objectOrNull('file');   // absent before S5W 1.2.0

        return new self(
            id: $p->intOrNull('id'),
            url: $p->string('url'),
            file: $file === null ? null : FileRef::fromPayload($file),
            expiresAt: $p->time('expiresAt'),
            revocable: $p->bool('revocable'),
            maxDownloads: $p->intOrNull('maxDownloads'),
            downloads: $p->int('downloads'),
            revoked: $p->bool('revoked'),
            disposition: $p->enum('disposition', Disposition::class),
            note: $p->string('note'),
        );
    }

    /**
     * Stored by the server: listed, revocable on its own.
     *
     * @link https://winterframe.net/packages/sdk-s5w/entities#link Link
     */
    public function isTracked(): bool
    {
        return $this->id !== null;
    }

    /** @link https://winterframe.net/packages/sdk-s5w/entities#link Link */
    public function isExpired(?\DateTimeInterface $now = null): bool
    {
        return $this->expiresAt <= ($now ?? new \DateTimeImmutable());
    }

    /**
     * The download limit is reached.
     *
     * @link https://winterframe.net/packages/sdk-s5w/entities#link Link
     */
    public function isUsedUp(): bool
    {
        return $this->maxDownloads !== null && $this->downloads >= $this->maxDownloads;
    }

    /**
     * Opens right now: not expired, not revoked, not used up — as far as this snapshot knows.
     *
     * @link https://winterframe.net/packages/sdk-s5w/entities#link Link
     */
    public function isActive(?\DateTimeInterface $now = null): bool
    {
        return !$this->revoked && !$this->isUsedUp() && !$this->isExpired($now);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'file' => $this->file?->toArray(),
            'expiresAt' => $this->expiresAt->format(\DATE_ATOM),
            'revocable' => $this->revocable,
            'maxDownloads' => $this->maxDownloads,
            'downloads' => $this->downloads,
            'revoked' => $this->revoked,
            'disposition' => $this->disposition->name,
            'note' => $this->note,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
