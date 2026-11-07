<?php

declare(strict_types=1);

namespace Flytachi\Winter\SDK\S5w\Api;

use Flytachi\Winter\SDK\S5w\Entity\File;
use Flytachi\Winter\SDK\S5w\Entity\UploadSession;
use Flytachi\Winter\SDK\S5w\Exception\ApiException;
use Flytachi\Winter\SDK\S5w\Exception\ConflictException;
use Flytachi\Winter\SDK\S5w\Exception\NotFoundException;
use Flytachi\Winter\SDK\S5w\Exception\PayloadTooLargeException;
use Flytachi\Winter\SDK\S5w\Exception\ServerException;
use Flytachi\Winter\SDK\S5w\Exception\TransportException;
use Flytachi\Winter\SDK\S5w\Exception\UploadInterruptedException;
use Flytachi\Winter\SDK\S5w\Exception\UploadUnconfirmedException;
use Flytachi\Winter\SDK\S5w\Exception\ValidationException;
use Flytachi\Winter\SDK\S5w\Http\HttpClient;
use Flytachi\Winter\SDK\S5w\Option\ImageOptions;
use Flytachi\Winter\SDK\S5w\S5wException;
use Flytachi\Winter\SDK\S5w\Support\Source;

/**
 * `/v1/uploads` — the chunked, resumable upload. Needs a FULL key.
 *
 * **Whole flow in one call** — for most needs `files()->upload()` already does this
 * by itself for large files; call it directly for progress or to keep the session id:
 * ```
 * $file = $s5w->uploads()->send('/videos/talk.mp4', folder: 'videos',
 *     onStart: fn(UploadSession $s) => $jobs->remember($s->id),
 *     onProgress: fn(int $sent, int $total) => printf("%d%%\n", $sent * 100 / $total),
 * );
 * $file = $s5w->uploads()->resume($savedId, '/videos/talk.mp4');   // after a restart
 * ```
 *
 * **Step by step** — one method per request, the loop is yours:
 * ```
 * $s = $uploads->start('talk.mp4', $size, folder: 'videos');
 * while (!$s->isComplete()) {
 *     $s = $uploads->append($s, $nextChunkOf($s->offset, $s->chunkSize));
 * }
 * $file = $uploads->complete($s);
 * ```
 *
 * Chunks: 4–16 MiB (the last may be smaller), strictly in order. A session lives
 * 24 hours after its last chunk and does not count against the quota until completed.
 */
final readonly class Uploads
{
    private const string PATH = 'v1/uploads';

    /** Attempts per chunk (and for completing) on a network or server failure. */
    public const int ATTEMPTS = 3;

    /** Pause before the first retry; doubles with each next one. */
    private const int BACKOFF_MS = 1000;

    /** @internal Reach it through {@see \Flytachi\Winter\SDK\S5w\S5w::uploads()}. */
    public function __construct(private HttpClient $http)
    {
    }

    // ── Step by step ─────────────────────────────────────────────────────────

    /**
     * Open a session. Everything that can be refused is refused here, before any
     * byte moves: a missing folder, a size the quota cannot hold, a full disk.
     *
     * With `$sha256` of content the bucket already holds, the file is created on the
     * spot: the answer {@see UploadSession::isDeduplicated()}, its `file` is ready.
     *
     * @param int         $size   exact total in bytes
     * @param string|null $sha256 lowercase hex; also verified when the upload completes
     *
     * @throws ValidationException      no such folder, bad image options
     * @throws PayloadTooLargeException the size does not fit in the quota
     */
    public function start(
        string $name,
        int $size,
        ?string $folder = null,
        ?string $sha256 = null,
        ?ImageOptions $imageOptions = null,
    ): UploadSession {
        $body = ['name' => $name, 'size' => $size];
        if ($folder !== null && $folder !== '') {
            $body['folder'] = $folder;
        }
        if ($sha256 !== null) {
            $body['sha256'] = strtolower($sha256);
        }
        $body += $imageOptions?->toFields() ?? [];

        // No retries: with a known hash a start can create the file, and a retry a second one.
        $request = $this->http->request('POST', self::PATH)->retry(1)->withJsonBody($body);

        return UploadSession::fromPayload($this->http->sendJson($request, 'POST', self::PATH));
    }

    /**
     * Send the next chunk. With a session, it is declared to start at the session's
     * offset, and a mismatch (a chunk sent twice, one lost) is refused with a 409
     * instead of corrupting the file. With a bare id, pass `$offset` for the same check.
     *
     * @throws ConflictException        the chunk does not continue the upload — ask {@see status()}
     * @throws PayloadTooLargeException over 16 MiB, or past the declared size
     * @throws NotFoundException        the session is gone (completed, aborted, expired)
     */
    public function append(string|UploadSession $session, string $chunk, ?int $offset = null): UploadSession
    {
        $path = self::path($session);
        $offset ??= $session instanceof UploadSession ? $session->offset : null;

        $headers = ['Content-Type' => 'application/octet-stream'];
        if ($offset !== null) {
            $headers['Upload-Offset'] = (string) $offset;
        }

        $request = $this->http->transferRequest('PATCH', $path, headers: $headers)->withBody($chunk);

        return UploadSession::fromPayload($this->http->sendJson($request, 'PATCH', $path));
    }

    /**
     * Where the session stands — `offset` is what the server holds. After a broken
     * connection, ask this and continue from that number.
     *
     * @throws NotFoundException the session is gone: start a new one
     */
    public function status(string|UploadSession $session): UploadSession
    {
        return UploadSession::fromPayload($this->http->json('GET', self::path($session)));
    }

    /**
     * Turn the received bytes into a file: the hash is checked, image options are
     * applied. Only now does the file appear in the bucket; the session id dies here.
     *
     * @throws ConflictException   bytes are missing — the session stays open, send the rest
     * @throws ValidationException the data does not match the declared `sha256`
     */
    public function complete(string|UploadSession $session): File
    {
        $path = self::path($session) . '/complete';
        $request = $this->http->transferRequest('POST', $path);

        return File::fromPayload($this->http->sendJson($request, 'POST', $path));
    }

    /**
     * Drop the session and the bytes received so far. Optional: an abandoned session
     * is swept 24 hours after its last chunk; this frees the disk now.
     *
     * @throws NotFoundException already completed, aborted or swept
     */
    public function abort(string|UploadSession $session): void
    {
        $this->http->call('DELETE', self::path($session));
    }

    // ── Whole flow ───────────────────────────────────────────────────────────

    /**
     * Upload a file from disk in chunks, start to finish.
     *
     * Hashes the file first (unless `$deduplicate` is off): content the bucket already
     * holds is not sent at all. A failed chunk is retried {@see ATTEMPTS} times, each
     * time asking the server where it stands first, so nothing is sent twice or lost.
     *
     * @param string|\SplFileInfo $file
     * @param string|null $name        stored name; null — the file's own name
     * @param bool        $deduplicate hash locally and skip the transfer for known content.
     *                                 Not done with image options (see {@see start()}).
     * @param (callable(UploadSession): void)|null $onStart   right after the session opens —
     *                                 save `$session->id` here to {@see resume()} after a restart
     * @param (callable(int, int): void)|null      $onProgress `(bytesOnServer, totalBytes)`,
     *                                 after every chunk
     *
     * @throws UploadInterruptedException retries ran out; the session is kept for {@see resume()}
     * @throws UploadUnconfirmedException the last answer was lost; the file may exist
     * @throws ApiException               refused (folder, quota, image options); the session is dropped
     */
    public function send(
        string|\SplFileInfo $file,
        ?string $folder = null,
        ?string $name = null,
        ?ImageOptions $imageOptions = null,
        bool $deduplicate = true,
        ?callable $onStart = null,
        ?callable $onProgress = null,
    ): File {
        return $this->sendSource(
            Source::path($file),
            $folder,
            $name,
            $imageOptions,
            $deduplicate,
            $onStart,
            $onProgress,
        );
    }

    /**
     * {@see send()} for bytes held in memory.
     *
     * @param string $name stored name, with the extension
     * @param (callable(UploadSession): void)|null $onStart
     * @param (callable(int, int): void)|null      $onProgress
     */
    public function sendContent(
        string $content,
        string $name,
        ?string $folder = null,
        ?ImageOptions $imageOptions = null,
        bool $deduplicate = true,
        ?callable $onStart = null,
        ?callable $onProgress = null,
    ): File {
        return $this->sendSource(
            Source::content($content, $name),
            $folder,
            $name,
            $imageOptions,
            $deduplicate,
            $onStart,
            $onProgress,
        );
    }

    /**
     * Continue an interrupted upload — after {@see UploadInterruptedException}, or after
     * a restart with an id saved in `onStart`. Asks the server where it stopped and
     * sends the rest of the same file.
     *
     * @param string|UploadSession $session the session or its id
     * @param (callable(int, int): void)|null $onProgress
     *
     * @throws NotFoundException the session is gone (completed, aborted, expired): start over
     * @throws S5wException      the file is not the size the session was started with
     */
    public function resume(
        string|UploadSession $session,
        string|\SplFileInfo $file,
        ?callable $onProgress = null,
    ): File {
        $source = Source::path($file);
        $current = $this->status($session);
        if ($current->size !== $source->size) {
            throw new S5wException(sprintf(
                'S5W: upload %s expects %d bytes, the file has %d — not the same file',
                $current->id,
                $current->size,
                $source->size,
            ));
        }

        return $this->pump($current, $source, $onProgress);
    }

    /**
     * @internal The shared path of {@see send()}, {@see sendContent()} and `files()->upload()`.
     */
    public function sendSource(
        Source $source,
        ?string $folder,
        ?string $name,
        ?ImageOptions $imageOptions,
        bool $deduplicate = true,
        ?callable $onStart = null,
        ?callable $onProgress = null,
    ): File {
        // With image options the server stores the processed result, so a hash of the
        // original says nothing — and when it does match, the server would attach the
        // unprocessed original and skip the options. Only hash plain uploads.
        $hash = $deduplicate && ($imageOptions === null || $imageOptions->isEmpty()) ? $source->sha256() : null;

        $session = $this->start($name ?? $source->name, $source->size, $folder, $hash, $imageOptions);
        if ($session->file !== null) {
            if ($onProgress !== null) {
                $onProgress($session->size, $session->size);
            }
            return $session->file;
        }

        if ($onStart !== null) {
            $onStart($session);
        }

        return $this->pump($session, $source, $onProgress);
    }

    /** Send what is left, then complete. */
    private function pump(UploadSession $session, Source $source, ?callable $onProgress): File
    {
        $reported = $session->offset;
        if ($onProgress !== null) {
            $onProgress($session->offset, $session->size);
        }

        $failures = 0;
        while (!$session->isComplete()) {
            $chunk = $source->read($session->offset, min($session->chunkSize, $session->remaining()));
            if ($chunk === '') {
                $this->abortQuietly($session);
                throw new S5wException(sprintf(
                    'S5W: the file ended at %d of %d bytes — it changed during the upload',
                    $session->offset,
                    $session->size,
                ));
            }

            try {
                $session = $this->append($session, $chunk);
            } catch (TransportException | ServerException | ConflictException $e) {
                $session = $this->recover($session, $e, ++$failures);
            } catch (ApiException $e) {
                // The data itself is refused (too large, too small, session gone): no retry helps.
                $this->abortQuietly($session);
                throw $e;
            }

            // Moved forward — by the answer, or by a chunk that landed although its answer
            // was lost (seen through status). Either way it is progress, and the failure
            // count starts over.
            if ($session->offset > $reported) {
                $reported = $session->offset;
                $failures = 0;
                if ($onProgress !== null) {
                    $onProgress($session->offset, $session->size);
                }
            }
        }

        return $this->finish($session);
    }

    /**
     * After a failed chunk: wait, then ask the server where it stands — the chunk
     * may well have landed before the connection broke.
     */
    private function recover(UploadSession $session, S5wException $cause, int $failures): UploadSession
    {
        if ($failures >= self::ATTEMPTS) {
            throw new UploadInterruptedException($session->id, $session->offset, $session->size, $cause);
        }

        usleep(self::BACKOFF_MS * (2 ** ($failures - 1)) * 1000);

        try {
            return $this->status($session);
        } catch (TransportException | ServerException) {
            return $session; // still unreachable: the next attempt will tell
        }
    }

    private function finish(UploadSession $session): File
    {
        for ($failures = 1;; $failures++) {
            try {
                return $this->complete($session);
            } catch (TransportException | ServerException $e) {
                // Did it complete? A live session says no; a gone one says "maybe".
                try {
                    $this->status($session);
                } catch (NotFoundException) {
                    throw new UploadUnconfirmedException($session->id, $session->name, $e);
                } catch (TransportException | ServerException) {
                    // unknown yet — count it as a failure and try again
                }
                if ($failures >= self::ATTEMPTS) {
                    throw new UploadInterruptedException($session->id, $session->offset, $session->size, $e);
                }
                usleep(self::BACKOFF_MS * (2 ** ($failures - 1)) * 1000);
            } catch (ApiException $e) {
                $this->abortQuietly($session);
                throw $e;
            }
        }
    }

    private function abortQuietly(UploadSession $session): void
    {
        try {
            $this->abort($session);
        } catch (S5wException) {
            // already gone, or unreachable — it is swept in 24 h anyway
        }
    }

    private static function path(string|UploadSession $session): string
    {
        $id = $session instanceof UploadSession ? $session->id : $session;
        if ($id === '') {
            throw new S5wException('S5W: this upload has no session — it was deduplicated, the file is ready');
        }

        return self::PATH . '/' . rawurlencode($id);
    }
}
