<?php

declare(strict_types=1);

namespace Flytachi\Winter\SDK\S5w\Api;

use Flytachi\Winter\SDK\S5w\Entity\Folder;
use Flytachi\Winter\SDK\S5w\Entity\Page;
use Flytachi\Winter\SDK\S5w\Enum\CacheVisibility;
use Flytachi\Winter\SDK\S5w\Enum\Retention;
use Flytachi\Winter\SDK\S5w\Exception\ConflictException;
use Flytachi\Winter\SDK\S5w\Exception\NotFoundException;
use Flytachi\Winter\SDK\S5w\Exception\ValidationException;
use Flytachi\Winter\SDK\S5w\Http\HttpClient;

/**
 * `/v1/folders` — optional one-level grouping that carries public visibility,
 * retention and cache policy for every file inside. Needs a FULL key.
 *
 * ```
 * $folders = $s5w->folders();
 *
 * $folders->create('avatars', public: true);
 * $folders->get('avatars')->retention;              // Retention::NONE
 * $folders->setCache('avatars', 3600, CacheVisibility::SHARED);
 * foreach ($folders->all() as $folder) { … }
 * ```
 */
final readonly class Folders
{
    private const string PATH = 'v1/folders';

    /** @internal Reach it through {@see \Flytachi\Winter\SDK\S5w\S5w::folders()}. */
    public function __construct(private HttpClient $http)
    {
    }

    /**
     * One page of folders.
     *
     * @param int         $page   from 1
     * @param int         $limit  1–100
     * @param string|null $search part of a name
     * @return Page<Folder>
     */
    public function list(int $page = 1, int $limit = 25, ?string $search = null): Page
    {
        $payload = $this->http->json('GET', self::PATH, query: [
            'page' => $page,
            'limit' => $limit,
            'search' => $search,
        ]);

        return Page::fromPayload($payload, Folder::fromPayload(...));
    }

    /**
     * Every folder, page after page, fetched lazily as you iterate.
     *
     * @return \Generator<int, Folder>
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
     * @throws NotFoundException no folder by that name
     */
    public function get(string $name): Folder
    {
        return Folder::fromPayload($this->http->json('GET', self::path($name)));
    }

    /** {@see get()}, but `null` instead of a 404. */
    public function find(string $name): ?Folder
    {
        try {
            return $this->get($name);
        } catch (NotFoundException) {
            return null;
        }
    }

    public function exists(string $name): bool
    {
        return $this->find($name) !== null;
    }

    /**
     * @param string    $name      1–100 chars: letters, digits, space, `.`, `_`, `-`;
     *                             starts with a letter or a digit
     * @param bool      $public    files inside are served at `/o/…` without a key
     * @param Retention $retention files inside are deleted this long after creation
     *
     * @throws ConflictException   the name is taken
     * @throws ValidationException the name breaks the rules
     */
    public function create(string $name, bool $public = false, Retention $retention = Retention::NONE): Folder
    {
        return Folder::fromPayload($this->http->json('POST', self::PATH, [
            'name' => $name,
            'public' => $public,
            'retention' => $retention->value,
        ]));
    }

    /**
     * The existing folder as it is — its settings are not touched — or a new one
     * {@see create()}d with these settings.
     *
     * Looks first, since the folder usually exists: one request in the common case,
     * and no 409 in the logs. A 409 from the create still means "someone created it
     * between our two requests", so it falls back to reading it.
     */
    public function ensure(string $name, bool $public = false, Retention $retention = Retention::NONE): Folder
    {
        $folder = $this->find($name);
        if ($folder !== null) {
            return $folder;
        }

        try {
            return $this->create($name, $public, $retention);
        } catch (ConflictException) {
            return $this->get($name);
        }
    }

    /**
     * Change a folder. Only what you pass changes; the rest is kept.
     *
     * The server replaces all three fields at once, so this reads the folder first
     * when something is left out (two requests instead of one).
     *
     * Both settings reach the files inside: `public` rewrites the flag on every file,
     * `retention` recomputes every file's expiry from its creation date — going from
     * YEAR to DAY can delete files older than a day on the next sweep.
     *
     * @param string|null $rename new name
     *
     * @throws NotFoundException  no folder by that name
     * @throws ConflictException  `rename` is taken by another folder
     */
    public function update(
        string $name,
        ?string $rename = null,
        ?bool $public = null,
        ?Retention $retention = null,
    ): Folder {
        if ($public === null || $retention === null) {
            $current = $this->get($name);
            $public ??= $current->public;
            $retention ??= $current->retention;
        }

        return Folder::fromPayload($this->http->json('PUT', self::path($name), [
            'name' => $rename ?? $name,
            'public' => $public,
            'retention' => $retention->value,
        ]));
    }

    public function rename(string $name, string $newName): Folder
    {
        return $this->update($name, rename: $newName);
    }

    /**
     * The `Cache-Control` of files in this folder. A `null` drops that part of the
     * override, and it is inherited from the bucket.
     *
     * Applies from the next request for a file; copies already in a CDN keep the old
     * header until they expire.
     *
     * @param int|null $maxAge seconds, 0 … 31536000
     */
    public function setCache(string $name, ?int $maxAge, ?CacheVisibility $visibility): Folder
    {
        return Folder::fromPayload($this->http->json('PATCH', self::path($name) . '/cache', [
            'maxAge' => $maxAge,
            'visibility' => $visibility?->value,
        ]));
    }

    /** Drop the folder's cache override: inherit the bucket policy again. */
    public function resetCache(string $name): Folder
    {
        return $this->setCache($name, null, null);
    }

    /**
     * Delete the folder **and every file inside it**. There is no undo.
     *
     * @throws NotFoundException the folder is already gone
     */
    public function delete(string $name): void
    {
        $this->http->call('DELETE', self::path($name));
    }

    /** A name may hold spaces and non-latin letters, so it is percent-encoded. */
    private static function path(string $name): string
    {
        return self::PATH . '/' . rawurlencode($name);
    }
}
