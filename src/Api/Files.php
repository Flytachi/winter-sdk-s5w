<?php

declare(strict_types=1);

namespace Flytachi\Winter\SDK\S5w\Api;

use Flytachi\Winter\SDK\S5w\Entity\File;
use Flytachi\Winter\SDK\S5w\Entity\Folder;
use Flytachi\Winter\SDK\S5w\Entity\Page;
use Flytachi\Winter\SDK\S5w\Enum\FileSort;
use Flytachi\Winter\SDK\S5w\Enum\SortDirection;
use Flytachi\Winter\SDK\S5w\Exception\NotFoundException;
use Flytachi\Winter\SDK\S5w\Exception\PayloadTooLargeException;
use Flytachi\Winter\SDK\S5w\Exception\ValidationException;
use Flytachi\Winter\SDK\S5w\Http\HttpClient;
use Flytachi\Winter\SDK\S5w\Option\ImageOptions;
use Flytachi\Winter\SDK\S5w\Support\Source;

/**
 * `/v1/files` — the files of the bucket. Needs a FULL key.
 *
 * ```
 * $file = $s5w->files()->upload('/tmp/report.pdf', folder: 'docs');
 * $file->id;          // "FXOkqegbdrNRWQgc" — keep it, it is how the file is addressed
 *
 * $s5w->files()->upload($tmp, folder: 'avatars', imageOptions: ImageOptions::webp()->fit(512, 512));
 * $s5w->files()->uploadContent($pdfBytes, 'invoice-42.pdf', folder: 'invoices');
 *
 * $s5w->files()->get('FXOkqegbdrNRWQgc');                       // File, or NotFoundException
 * $s5w->files()->find($id);                                     // ?File
 * $s5w->files()->list(folder: 'docs', sort: FileSort::SIZE);     // Page<File>
 * $s5w->files()->delete($file);
 * foreach ($s5w->files()->all(folder: Folder::ROOT) as $file) { … }
 * ```
 */
final readonly class Files
{
    private const string PATH = 'v1/files';

    /**
     * Largest file sent in one multipart request. The server caps the whole request
     * body at 32 MiB; the rest is room for the multipart framing and the fields.
     */
    public const int SINGLE_REQUEST_LIMIT = 32 * 1024 * 1024 - 64 * 1024;

    /** @internal Reach it through {@see \Flytachi\Winter\SDK\S5w\S5w::files()}. */
    public function __construct(
        private HttpClient $http,
        private Uploads $uploads,
    ) {
    }

    // ── Reading ──────────────────────────────────────────────────────────────

    /**
     * One file by id. The same object the upload returned, minus `processed`.
     *
     * @param string|File $id the file's id, or the file itself (to re-read it)
     *
     * @throws NotFoundException no such file in this bucket — one from another bucket
     *                           answers the same way. An id that is not even shaped like
     *                           one ({@see File::isValidId()}) is the same answer, without
     *                           a request.
     */
    public function get(string|File $id): File
    {
        return File::fromPayload($this->http->json('GET', self::path($id, 'GET')));
    }

    /** {@see get()}, but `null` instead of a 404 (a malformed id included). */
    public function find(string|File $id): ?File
    {
        try {
            return $this->get($id);
        } catch (NotFoundException) {
            return null;
        }
    }

    public function exists(string|File $id): bool
    {
        return $this->find($id) !== null;
    }

    /**
     * One page of files.
     *
     * @param int                $page   from 1
     * @param int                $limit  1–100
     * @param string|null        $search part of the name, case-insensitive
     * @param string|Folder|null $folder one folder (by name or entity); {@see Folder::ROOT} —
     *                                   only the files outside any folder; null — the whole bucket
     * @return Page<File>
     *
     * @throws ValidationException no such folder
     */
    public function list(
        int $page = 1,
        int $limit = 25,
        ?string $search = null,
        string|Folder|null $folder = null,
        FileSort $sort = FileSort::CREATED,
        SortDirection $direction = SortDirection::DESC,
    ): Page {
        $payload = $this->http->json('GET', self::PATH, query: [
            'page' => $page,
            'limit' => $limit,
            'search' => $search === '' ? null : $search,
            'folder' => self::folderFilter($folder),
            'sort' => $sort->value,
            'dir' => $direction->value,
        ]);

        return Page::fromPayload($payload, File::fromPayload(...));
    }

    /**
     * Every matching file, page after page, fetched lazily as you iterate.
     *
     * @param string|Folder|null $folder as in {@see list()}
     * @return \Generator<int, File>
     *
     * @throws ValidationException no such folder
     */
    public function all(
        ?string $search = null,
        string|Folder|null $folder = null,
        FileSort $sort = FileSort::CREATED,
        SortDirection $direction = SortDirection::DESC,
        int $pageSize = 100,
    ): \Generator {
        $page = 1;
        do {
            $result = $this->list($page, $pageSize, $search, $folder, $sort, $direction);
            yield from $result->items;
            $page = $result->next;
        } while ($page !== null);
    }

    // ── Changing ─────────────────────────────────────────────────────────────

    /**
     * Delete a file. It is gone from every delivery URL at once; temporary links to it
     * start answering 404. The bytes stay while another file shares them
     * (deduplication) — the other file is not affected.
     *
     * @param string|File $id the file's id, or the file itself
     *
     * @throws NotFoundException no such file — already deleted, or never existed.
     *                           Deleting is not idempotent: on a retry, treat it as done.
     */
    public function delete(string|File $id): void
    {
        $this->http->call('DELETE', self::path($id, 'DELETE'));
    }

    // ── Uploading ────────────────────────────────────────────────────────────

    /**
     * Upload a file from disk.
     *
     * Picks the way by size: up to {@see \Flytachi\Winter\SDK\S5w\S5wConfig::$chunkThreshold}
     * (16 MiB by default) in one request; larger in chunks through
     * {@see Uploads::send()} — resumable, retried, deduplicated by hash. For progress
     * or the session id, call `uploads()->send()` yourself.
     *
     * @param string|\SplFileInfo $file   path to a readable file
     * @param string|null         $folder folder name; null — the bucket root
     * @param string|null         $name   stored name; null — the file's own name.
     *                                    Taken in the folder already → `name (1).ext`.
     *                                    Uploading a temporary file (e.g. a request upload's
     *                                    `tmp_name`)? Pass the original name here, or the
     *                                    temporary one is stored.
     * @param ImageOptions|null   $imageOptions re-encode / resize; for images only
     *
     * @throws ValidationException      no such folder, bad image options, image options on a non-image
     * @throws PayloadTooLargeException the bucket quota is full
     */
    public function upload(
        string|\SplFileInfo $file,
        ?string $folder = null,
        ?string $name = null,
        ?ImageOptions $imageOptions = null,
    ): File {
        $source = Source::path($file);
        if ($source->size > $this->http->config()->chunkThreshold) {
            return $this->uploads->sendSource($source, $folder, $name, $imageOptions);
        }

        $path = (string) $source->pathname();

        return $this->send(new \CURLFile($path, '', basename($path)), $folder, $name, $imageOptions);
    }

    /**
     * Upload bytes held in memory — a generated PDF, a decoded base64 image.
     *
     * @param string $content the bytes
     * @param string $name    stored name, with the extension
     *
     * @throws ValidationException      no such folder, bad image options, image options on a non-image
     * @throws PayloadTooLargeException the bucket quota is full
     */
    public function uploadContent(
        string $content,
        string $name,
        ?string $folder = null,
        ?ImageOptions $imageOptions = null,
    ): File {
        if (strlen($content) > $this->http->config()->chunkThreshold) {
            return $this->uploads->sendSource(Source::content($content, $name), $folder, $name, $imageOptions);
        }

        return $this->send(new \CURLStringFile($content, $name), $folder, null, $imageOptions);
    }

    private function send(
        \CURLFile|\CURLStringFile $part,
        ?string $folder,
        ?string $name,
        ?ImageOptions $imageOptions,
    ): File {
        $fields = ['file' => $part];
        if ($folder !== null && $folder !== '') {
            $fields['folder'] = $folder;
        }
        if ($name !== null && $name !== '') {
            $fields['name'] = $name;
        }
        foreach ($imageOptions?->toFields() ?? [] as $key => $value) {
            $fields[$key] = (string) $value;
        }

        $request = $this->http->transferRequest('POST', self::PATH)->withMultipartBody($fields);

        return File::fromPayload($this->http->sendJson($request, 'POST', self::PATH));
    }

    /**
     * The path of one file. A string that cannot be a file id answers like a missing
     * file would — a 404 — without asking the server (which would refuse it with a 422).
     */
    private static function path(string|File $id, string $method): string
    {
        if ($id instanceof File) {
            return self::PATH . '/' . $id->id;
        }

        $path = self::PATH . '/' . rawurlencode($id);
        if (!File::isValidId($id)) {
            throw NotFoundException::from(404, 'File does not exist (not a valid file id)', [], $method, $path);
        }

        return $path;
    }

    /**
     * The `folder` query value: null — not sent (whole bucket); `''` — the root;
     * otherwise the folder's name.
     */
    private static function folderFilter(string|Folder|null $folder): ?string
    {
        return match (true) {
            $folder instanceof Folder => $folder->name,
            $folder === Folder::ROOT => '',
            $folder === '' => null,
            default => $folder,
        };
    }
}
