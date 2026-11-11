<?php

declare(strict_types=1);

namespace Flytachi\Winter\SDK\S5w\Api;

use Flytachi\Winter\SDK\S5w\Entity\File;
use Flytachi\Winter\SDK\S5w\Entity\Folder;
use Flytachi\Winter\SDK\S5w\Entity\Page;
use Flytachi\Winter\SDK\S5w\Enum\FileSort;
use Flytachi\Winter\SDK\S5w\Enum\SortDirection;
use Flytachi\Winter\SDK\S5w\Exception\ConflictException;
use Flytachi\Winter\SDK\S5w\Exception\ContentTooLargeException;
use Flytachi\Winter\SDK\S5w\Exception\NotFoundException;
use Flytachi\Winter\SDK\S5w\Exception\PayloadTooLargeException;
use Flytachi\Winter\SDK\S5w\Exception\ResponseException;
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
 * $s5w->files()->rename($file, 'Q3 report.pdf');
 * $s5w->files()->move($file, 'archive');                        // or Folder::ROOT for the root
 * $s5w->files()->update($file, 'Q3-final.pdf', 'archive');       // both at once, one request
 * $s5w->files()->delete($file);
 * $bytes = $s5w->files()->contents($file);                    // the bytes, up to 16 MiB
 * foreach ($s5w->files()->all(folder: Folder::ROOT) as $file) { … }
 * ```
 *
 * @link https://winterframe.net/packages/sdk-s5w/api-reference#files Files
 */
final readonly class Files
{
    private const string PATH = 'v1/files';

    /**
     * Largest file sent in one multipart request. The server caps the whole request
     * body at 32 MiB; the rest is room for the multipart framing and the fields.
     */
    public const int SINGLE_REQUEST_LIMIT = 32 * 1024 * 1024 - 64 * 1024;

    /** The default limit of {@see contents()}: the same 16 MiB above which uploads go in chunks. */
    public const int CONTENTS_LIMIT = 16 * 1024 * 1024;

    private const string DELIVERY_PATH = 'p';

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
     *
     * @link https://winterframe.net/packages/sdk-s5w/api-reference#filesget Files::get()
     */
    public function get(string|File $id): File
    {
        return File::fromPayload($this->http->json('GET', self::path($id, 'GET')));
    }

    /**
     * {@see get()}, but `null` instead of a 404 (a malformed id included).
     *
     * @link https://winterframe.net/packages/sdk-s5w/api-reference#filesfind-filesexists Files::find()
     */
    public function find(string|File $id): ?File
    {
        try {
            return $this->get($id);
        } catch (NotFoundException) {
            return null;
        }
    }

    /** @link https://winterframe.net/packages/sdk-s5w/api-reference#filesfind-filesexists Files::exists() */
    public function exists(string|File $id): bool
    {
        return $this->find($id) !== null;
    }

    /**
     * The file's bytes as a string — for small files: a JSON document, an image to process.
     * The whole file is held in memory, so it is capped: 16 MiB unless `$maxSize` says
     * otherwise. Larger files belong on disk.
     *
     * ```
     * $data  = json_decode($s5w->files()->contents($id), true);
     * $image = imagecreatefromstring($s5w->files()->contents($file, maxSize: 50 * 1024 * 1024));
     * ```
     *
     * Passing the `File` (not just its id) buys two things: a file over the limit is refused
     * without a request, and the bytes are checked against the file's sha256.
     *
     * Every call counts as a delivery in the bucket's traffic, like any download.
     *
     * @param string|File $file    the file's id, or the file itself
     * @param int|null    $maxSize the limit in bytes; null — {@see CONTENTS_LIMIT}
     *
     * @throws NotFoundException         no such file in this bucket (or not a valid id — without a request)
     * @throws ContentTooLargeException  over the limit
     * @throws ResponseException         the bytes do not match the file's sha256 (only with a `File`)
     *
     * @link https://winterframe.net/packages/sdk-s5w/api-reference#filescontents Files::contents()
     */
    public function contents(string|File $file, ?int $maxSize = null): string
    {
        $limit = $maxSize ?? self::CONTENTS_LIMIT;
        if ($limit < 1) {
            throw new \InvalidArgumentException("S5W: maxSize must be positive, {$limit} given");
        }

        $path = self::deliveryPath($file);
        $id = $file instanceof File ? $file->id : $file;
        if ($file instanceof File && $file->content->size > $limit) {
            throw new ContentTooLargeException($id, $limit, $file->content->size);
        }

        $bytes = $this->http->fetch($path, $id, $limit);

        if ($file instanceof File && !hash_equals(strtolower($file->content->hash), hash('sha256', $bytes))) {
            throw new ResponseException("S5W {$path}: the content does not match the file's sha256");
        }

        return $bytes;
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
     * @link https://winterframe.net/packages/sdk-s5w/api-reference#fileslist Files::list()
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
     * @link https://winterframe.net/packages/sdk-s5w/api-reference#filesall Files::all()
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
     *
     * @link https://winterframe.net/packages/sdk-s5w/api-reference#filesdelete Files::delete()
     */
    public function delete(string|File $id): void
    {
        $this->http->call('DELETE', self::path($id, 'DELETE'));
    }

    /**
     * Rename a file; it stays in its folder. The id does not change, so every URL and
     * temporary link keeps working — under the new name.
     *
     * The server takes the name as it is: the extension is not added or checked.
     * Reads the file first (the server replaces name and folder together).
     *
     * @param string $name 1–255 characters, unique in the folder
     *
     * @throws NotFoundException no such file
     * @throws ConflictException a file with that name is already in the folder
     * @link https://winterframe.net/packages/sdk-s5w/api-reference#filesrename Files::rename()
     */
    public function rename(string|File $id, string $name): File
    {
        $current = $this->get($id);

        return $this->place($current, $name, $current->folder);
    }

    /**
     * Move a file to another folder, or to the bucket root; the name stays. The id does
     * not change, so URLs and temporary links keep working.
     *
     * The destination decides: a public folder makes the file public, a private one
     * private, and its retention is applied from the file's creation date. The bucket
     * root makes a file public. Reads the file first.
     *
     * @param string|Folder $folder destination, required; {@see Folder::ROOT} (or `''`) — the root
     *
     * @throws NotFoundException   no such file
     * @throws ValidationException no such folder
     * @throws ConflictException   a file with that name is already in the destination
     * @link https://winterframe.net/packages/sdk-s5w/api-reference#filesmove Files::move()
     */
    public function move(string|File $id, string|Folder $folder): File
    {
        $current = $this->get($id);

        return $this->place($current, $current->name, Folder::nameOf($folder));
    }

    /**
     * Set the name and the folder at once — one request, nothing read first. What you
     * pass is what the file ends up with, so a concurrent change cannot be undone by
     * a stale read ({@see rename()} and {@see move()} read the file first).
     *
     * Both are required on purpose, and the folder cannot be null: the root makes a file
     * public, so moving there has to be spelled out — {@see Folder::ROOT} (or `''`).
     * To keep the folder, use {@see rename()}.
     *
     * @param string        $name   1–255 characters, unique in the destination
     * @param string|Folder $folder destination; {@see Folder::ROOT} (or `''`) — the root
     *
     * @throws NotFoundException   no such file
     * @throws ValidationException no such folder
     * @throws ConflictException   a file with that name is already in the destination
     * @link https://winterframe.net/packages/sdk-s5w/api-reference#filesupdate Files::update()
     */
    public function update(string|File $id, string $name, string|Folder $folder): File
    {
        return $this->place($id, $name, Folder::nameOf($folder));
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
     * @param string|Folder|null  $folder folder name or entity; null — the bucket root
     * @param string|null         $name   stored name; null — the file's own name.
     *                                    Taken in the folder already → `name (1).ext`.
     *                                    Uploading a temporary file (e.g. a request upload's
     *                                    `tmp_name`)? Pass the original name here, or the
     *                                    temporary one is stored.
     * @param ImageOptions|null   $imageOptions re-encode / resize; for images only
     *
     * @throws ValidationException      no such folder, bad image options, image options on a non-image
     * @throws PayloadTooLargeException the bucket quota is full
     * @link https://winterframe.net/packages/sdk-s5w/api-reference#filesupload Files::upload()
     */
    public function upload(
        string|\SplFileInfo $file,
        string|Folder|null $folder = null,
        ?string $name = null,
        ?ImageOptions $imageOptions = null,
    ): File {
        $source = Source::path($file);
        if ($source->size > $this->http->config()->chunkThreshold) {
            return $this->uploads->sendSource($source, $folder, $name, $imageOptions);
        }

        $path = (string) $source->pathname();
        // The server takes the extension from this file name when it cannot tell the type from
        // the content. A given name is what the caller means; the path may be a temporary
        // upload (`/tmp/swoole.upfile.a1b2c3`) whose "extension" is noise.
        $postname = $name !== null && $name !== '' ? $name : basename($path);

        return $this->send(new \CURLFile($path, '', $postname), $folder, $name, $imageOptions);
    }

    /**
     * Upload bytes held in memory — a generated PDF, a decoded base64 image.
     *
     * @param string $content the bytes
     * @param string $name    stored name, with the extension
     *
     * @throws ValidationException      no such folder, bad image options, image options on a non-image
     * @throws PayloadTooLargeException the bucket quota is full
     * @link https://winterframe.net/packages/sdk-s5w/api-reference#filesuploadcontent Files::uploadContent()
     */
    public function uploadContent(
        string $content,
        string $name,
        string|Folder|null $folder = null,
        ?ImageOptions $imageOptions = null,
    ): File {
        if (strlen($content) > $this->http->config()->chunkThreshold) {
            return $this->uploads->sendSource(Source::content($content, $name), $folder, $name, $imageOptions);
        }

        return $this->send(new \CURLStringFile($content, $name), $folder, null, $imageOptions);
    }

    private function send(
        \CURLFile|\CURLStringFile $part,
        string|Folder|null $folder,
        ?string $name,
        ?ImageOptions $imageOptions,
    ): File {
        $fields = ['file' => $part];
        $folder = Folder::nameOf($folder);
        if ($folder !== null) {
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

    /** `PUT` replaces both: the name and the folder (null — the root). */
    private function place(string|File $id, string $name, ?string $folder): File
    {
        $path = self::path($id, 'PUT');

        return File::fromPayload($this->http->json('PUT', $path, [
            'name' => $name,
            'folder' => $folder,
        ]));
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

    /** `p/{id}` — where the file's bytes are served; a malformed id is a 404 without a request. */
    private static function deliveryPath(string|File $id): string
    {
        if ($id instanceof File) {
            return self::DELIVERY_PATH . '/' . $id->id;
        }
        $path = self::DELIVERY_PATH . '/' . rawurlencode($id);
        if (!File::isValidId($id)) {
            throw NotFoundException::from(404, 'File does not exist (not a valid file id)', [], 'GET', $path);
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
