<?php

declare(strict_types=1);

namespace Flytachi\Winter\SDK\S5w\Api;

use Flytachi\Winter\SDK\S5w\Entity\File;
use Flytachi\Winter\SDK\S5w\Exception\PayloadTooLargeException;
use Flytachi\Winter\SDK\S5w\Exception\ValidationException;
use Flytachi\Winter\SDK\S5w\Http\HttpClient;
use Flytachi\Winter\SDK\S5w\Option\ImageOptions;
use Flytachi\Winter\SDK\S5w\S5wException;

/**
 * `/v1/files` — the files of the bucket. Needs a FULL key.
 *
 * ```
 * $file = $s5w->files()->upload('/tmp/report.pdf', folder: 'docs');
 * $file->slug;        // "FXOkqegbdrNRWQgc" — keep it, it is how the file is addressed
 *
 * $s5w->files()->upload($tmp, folder: 'avatars', image: ImageOptions::webp()->fit(512, 512));
 * $s5w->files()->uploadContent($pdfBytes, 'invoice-42.pdf', folder: 'invoices');
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
    public function __construct(private HttpClient $http)
    {
    }

    /**
     * Upload a file from disk.
     *
     * @param string|\SplFileInfo $file   path to a readable file
     * @param string|null         $folder folder name; null — the bucket root
     * @param string|null         $name   stored name; null — the file's own name.
     *                                    Taken in the folder already → `name (1).ext`
     * @param ImageOptions|null   $image  re-encode / resize; for images only
     *
     * @throws ValidationException      no such folder, bad image options, image options on a non-image
     * @throws PayloadTooLargeException the bucket quota is full
     */
    public function upload(
        string|\SplFileInfo $file,
        ?string $folder = null,
        ?string $name = null,
        ?ImageOptions $image = null,
    ): File {
        $path = $file instanceof \SplFileInfo ? $file->getPathname() : $file;
        if (!is_file($path) || !is_readable($path)) {
            throw new S5wException("S5W: cannot read the file '{$path}'");
        }

        $size = (int) filesize($path);
        self::assertSingleRequest($size);

        return $this->send(new \CURLFile($path, '', basename($path)), $folder, $name, $image);
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
        ?ImageOptions $image = null,
    ): File {
        self::assertSingleRequest(strlen($content));

        return $this->send(new \CURLStringFile($content, $name), $folder, null, $image);
    }

    private function send(\CURLFile|\CURLStringFile $part, ?string $folder, ?string $name, ?ImageOptions $image): File
    {
        $fields = ['file' => $part];
        if ($folder !== null && $folder !== '') {
            $fields['folder'] = $folder;
        }
        if ($name !== null && $name !== '') {
            $fields['name'] = $name;
        }
        foreach ($image?->toFields() ?? [] as $key => $value) {
            $fields[$key] = (string) $value;
        }

        $request = $this->http->transferRequest('POST', self::PATH)->withMultipartBody($fields);

        return File::fromPayload($this->http->sendJson($request, 'POST', self::PATH));
    }

    private static function assertSingleRequest(int $size): void
    {
        if ($size > self::SINGLE_REQUEST_LIMIT) {
            // Larger files go through the chunked upload (/v1/uploads); upload() will
            // switch to it by itself once it is in the SDK.
            throw new S5wException(sprintf(
                'S5W: %d bytes is over the single-request limit of %d; the chunked upload is not in the SDK yet',
                $size,
                self::SINGLE_REQUEST_LIMIT,
            ));
        }
    }
}
