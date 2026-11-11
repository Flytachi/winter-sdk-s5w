<?php

declare(strict_types=1);

namespace Flytachi\Winter\SDK\S5w\Api;

use Flytachi\Winter\SDK\S5w\Entity\File;
use Flytachi\Winter\SDK\S5w\Entity\Link;
use Flytachi\Winter\SDK\S5w\Entity\Page;
use Flytachi\Winter\SDK\S5w\Enum\Disposition;
use Flytachi\Winter\SDK\S5w\Exception\NotFoundException;
use Flytachi\Winter\SDK\S5w\Exception\ValidationException;
use Flytachi\Winter\SDK\S5w\Http\HttpClient;
use Flytachi\Winter\SDK\S5w\S5wException;

/**
 * Temporary links `/t/…` — a file opened without a key, until the link expires. Needs a FULL key.
 *
 * ```
 * $link = $s5w->links()->issue($file);                                   // signed, one hour
 * $link = $s5w->links()->issue($file, ttl: new DateInterval('P1D'),
 *     disposition: Disposition::ATTACHMENT, maxDownloads: 3, note: 'for the contractor');
 *
 * $s5w->links()->ofFile($file);       // the file's last 20 tracked links
 * $s5w->links()->list(search: 'invoice'); // Page<Link> — the bucket's tracked links
 * $s5w->links()->revoke($link);       // a tracked one
 * $s5w->links()->revokeAll();         // all of them, signed ones included
 * ```
 *
 * @link https://winterframe.net/packages/sdk-s5w/api-reference#links Links
 */
final readonly class Links
{
    private const string PATH = 'v1/links';

    /** The longest a link may live: 30 days. */
    public const int MAX_TTL = 2_592_000;

    /** @internal Reach it through {@see \Flytachi\Winter\SDK\S5w\S5w::links()}. */
    public function __construct(private HttpClient $http)
    {
    }

    /**
     * Issue a temporary link to a file.
     *
     * Without `$maxDownloads` and `$revocable` the link is **signed**: the server does not store
     * it, and it can be revoked only together with all others ({@see revokeAll()}). Either of
     * them makes it **tracked**: listed, revocable on its own, and able to keep a `$note`.
     * A link with `$maxDownloads` serves the file whole — no `Range`, so no resumed downloads.
     *
     * @param string|File            $file         the file's id, or the file itself
     * @param int|\DateInterval      $ttl          lifetime: seconds, or an interval; at most 30 days
     * @param int|null               $maxDownloads 1–1000; makes the link tracked
     * @param bool                   $revocable    revocable on its own; makes the link tracked
     * @param string|null            $note         up to 255 characters; kept only on a tracked link
     *
     * @throws S5wException        a note on a signed link, or a lifetime that is not positive
     * @throws NotFoundException   no such file in this bucket (or not a valid id — without a request)
     * @throws ValidationException the server refused a value (e.g. a lifetime over 30 days)
     *
     * @link https://winterframe.net/packages/sdk-s5w/api-reference#linksissue Links::issue()
     */
    public function issue(
        string|File $file,
        int|\DateInterval $ttl = 3600,
        Disposition $disposition = Disposition::INLINE,
        ?int $maxDownloads = null,
        bool $revocable = false,
        ?string $note = null,
    ): Link {
        $seconds = self::seconds($ttl);
        $tracked = $maxDownloads !== null || $revocable;
        if ($note !== null && $note !== '' && !$tracked) {
            throw new S5wException(
                'S5W: a note is kept only on a tracked link — add revocable: true or maxDownloads',
            );
        }

        $body = ['ttl' => $seconds, 'disposition' => $disposition->value];
        if ($maxDownloads !== null) {
            $body['maxDownloads'] = $maxDownloads;
        }
        if ($revocable) {
            $body['revocable'] = true;
        }
        if ($note !== null && $note !== '') {
            $body['note'] = $note;
        }

        return Link::fromPayload($this->http->json('POST', self::filePath($file, 'link', 'POST'), $body));
    }

    /**
     * The file's last 20 tracked links, newest first — expired, revoked and used-up ones too
     * (the server keeps a link for 7 days after it expires). Signed links are not stored, so
     * they are never here.
     *
     * @return list<Link>
     *
     * @throws NotFoundException no such file
     * @link https://winterframe.net/packages/sdk-s5w/api-reference#linksoffile Links::ofFile()
     */
    public function ofFile(string|File $file): array
    {
        $payload = $this->http->json('GET', self::filePath($file, 'links', 'GET'));

        return array_map(Link::fromPayload(...), $payload->asList());
    }

    /**
     * One page of the bucket's tracked links, newest first.
     *
     * @param int         $page   from 1
     * @param int         $limit  1–100
     * @param string|null $search part of the link's note or of its file's name, case-insensitive
     * @return Page<Link>
     * @link https://winterframe.net/packages/sdk-s5w/api-reference#linkslist Links::list()
     */
    public function list(int $page = 1, int $limit = 25, ?string $search = null): Page
    {
        $payload = $this->http->json('GET', self::PATH, query: [
            'page' => $page,
            'limit' => $limit,
            'search' => $search === '' ? null : $search,
        ]);

        return Page::fromPayload($payload, Link::fromPayload(...));
    }

    /**
     * Every tracked link of the bucket, page after page, fetched lazily as you iterate.
     *
     * @return \Generator<int, Link>
     * @link https://winterframe.net/packages/sdk-s5w/api-reference#linksall Links::all()
     */
    public function all(?string $search = null, int $pageSize = 100): \Generator
    {
        $page = 1;
        do {
            $result = $this->list($page, $pageSize, $search);
            yield from $result->items;
            $page = $result->next;
        } while ($page !== null);
    }

    /**
     * Revoke one tracked link: it stops opening at once.
     *
     * @param int|Link $link the link, or its `id`
     *
     * @throws S5wException      a signed link — it has no id; only {@see revokeAll()} stops it
     * @throws NotFoundException no such link
     * @link https://winterframe.net/packages/sdk-s5w/api-reference#linksrevoke Links::revoke()
     */
    public function revoke(int|Link $link): void
    {
        $id = $link instanceof Link ? $link->id : $link;
        if ($id === null) {
            throw new S5wException(
                'S5W: a signed link cannot be revoked on its own — revokeAll() stops every link of the bucket',
            );
        }

        $this->http->call('DELETE', self::PATH . '/' . $id);
    }

    /**
     * Revoke **every** temporary link of the bucket, signed ones included — the way to stop a
     * leaked signed link. Links issued afterwards work as usual.
     *
     * @return int the new signing epoch: every link issued before it is void
     * @link https://winterframe.net/packages/sdk-s5w/api-reference#linksrevokeall Links::revokeAll()
     */
    public function revokeAll(): int
    {
        return $this->http->json('DELETE', self::PATH)->int('epoch');
    }

    /** @throws S5wException the lifetime is not positive */
    private static function seconds(int|\DateInterval $ttl): int
    {
        if ($ttl instanceof \DateInterval) {
            $now = new \DateTimeImmutable();
            $ttl = $now->add($ttl)->getTimestamp() - $now->getTimestamp();
        }
        if ($ttl < 1) {
            throw new S5wException("S5W: a link's lifetime must be positive, {$ttl} s given");
        }

        return $ttl;
    }

    /** `v1/files/{id}/{tail}`; a malformed id is a 404 without a request. */
    private static function filePath(string|File $file, string $tail, string $method): string
    {
        $id = $file instanceof File ? $file->id : $file;
        $path = 'v1/files/' . rawurlencode($id) . '/' . $tail;
        if (!File::isValidId($id)) {
            throw NotFoundException::from(404, 'File does not exist (not a valid file id)', [], $method, $path);
        }

        return $path;
    }
}
