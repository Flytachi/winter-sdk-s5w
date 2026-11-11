<div align="center">

<img src="docs/assets/s5w.svg" width="96" height="96" alt="S5W">

# Winter SDK for S5W

**The PHP client for [S5W](https://github.com/Flytachi/s5w) — a file store and CDN in one container.**<br>
Upload anything, any size; get typed objects back and typed errors when something goes wrong.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/flytachi/winter-sdk-s5w.svg)](https://packagist.org/packages/flytachi/winter-sdk-s5w)
[![PHP](https://img.shields.io/badge/php-%E2%89%A5%208.4-777bb4.svg?logo=php&logoColor=white)](https://www.php.net/releases/8.4/)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg)](LICENSE)
[![Docs](https://img.shields.io/badge/docs-winterframe.net-8b5cf6.svg)](https://winterframe.net/packages/sdk-s5w)

[Quick start](#quick-start) · [Uploading](#uploading) · [Folders and files](#folders-and-files) · [Errors](#errors) · [Documentation](#documentation)

</div>

---

You pass a path, you get a `File`. The SDK decides whether to send it in one request or in
chunks, retries a chunk that broke, skips the transfer when the bucket already holds the
same bytes, and turns every server refusal into an exception of a specific type.

```php
use Flytachi\Winter\SDK\S5w\S5w;

$s5w = S5w::connect('https://s5.example.com', 's5w_9f3c…');

$file = $s5w->files()->upload('/tmp/report.pdf', folder: 'docs');

$file->id;           // "FXOkqegbdrNRWQgc" — how the file is addressed everywhere
$file->privateUrl;   // https://s5.example.com/p/FXOkqegbdrNRWQgc
```

The same `upload()` call takes a multi-gigabyte file: it goes in resumable chunks, survives a
dropped connection and is not sent at all if the content is already in the bucket.

## Highlights

- ⏫ **Any size, one call** — up to 16 MiB in one request, larger in chunks; you don't choose, the SDK does.
- 🔁 **Survives a broken connection** — a failed chunk is retried after asking the server what it already holds: nothing sent twice, nothing lost.
- ⏯ **Resumable** — keep the session id, continue after a crash or a restart with `resume()`.
- ♻️ **Deduplication** — content the bucket already has is not transferred at all.
- 🖼 **Images on upload** — resize and re-encode to `webp` / `avif` / `jpeg` / `png` with a fluent `ImageOptions`.
- 🗂 **Folders and files** — visibility, retention, cache policy; search, sort, move, rename, delete.
- 🧱 **Typed all the way** — immutable entities, enums instead of magic numbers, an exception class per HTTP status.
- 🌱 **Made for Winter** — a bean in five lines, several buckets as named beans, PSR-3 logging.
- 🔒 **The key stays secret** — never in logs, never in a `var_dump()`.

---

## Requirements

| | Version | Why |
|---|---|---|
| PHP | `>= 8.4` | readonly classes, enums, promoted properties |
| `ext-curl` | any | all traffic goes through cURL; files are sent as `CURLFile` |
| S5W server | API `v1` | the SDK talks to `/v1/*` |

[`flytachi/winter-cast`](https://packagist.org/packages/flytachi/winter-cast) (HTTP) and
[`psr/log`](https://packagist.org/packages/psr/log) are installed by Composer. Any PSR-3
logger is optional.

## Installation

```bash
composer require flytachi/winter-sdk-s5w
```

You need two values from the S5W instance: its **address** and a **bucket key** issued in the
admin panel. The bucket is decided by the key — it is never configured in the SDK.

| Key | Opens |
|---|---|
| `FULL` | everything: `check()`, `folders()`, `files()`, `uploads()` |
| `BASIC` | `check()` and reading files — `files()->contents()` (the `/p/{id}` delivery) |

---

## Quick start

```php
$s5w = S5w::connect(getenv('S5W_URL'), getenv('S5W_KEY'));

// is the key good, and what is behind it?
$key = $s5w->check();
echo "{$key->bucket->name}: {$key->storage->free} bytes free\n";

// a folder — created once, reused afterwards
$folder = $s5w->folders()->ensure('reports');

// upload from memory and from disk
$csv   = $s5w->files()->uploadContent("date,total\n2026-11-01,42\n", 'november.csv', folder: $folder);
$notes = $s5w->files()->upload('/tmp/notes.txt', folder: $folder);

// read a small file back into memory (up to 16 MiB)
$text = $s5w->files()->contents($notes);

// list, rename, delete
foreach ($s5w->files()->list(folder: $folder) as $file) {
    echo $file->name, "\n";
}
$notes = $s5w->files()->rename($notes, 'Q4-notes.txt');   // the id does not change
$s5w->files()->delete($csv);
```

## In a Winter application

Declare the client as a bean — the container builds it once per worker:

```php
#[Configuration]
final class S5wConfiguration
{
    #[Bean]
    public function s5w(
        #[Value('S5W_URL')] string $url,
        #[Value('S5W_KEY')] string $key,
    ): S5w {
        return new S5w(new S5wConfig($url, $key), logger: LoggerFactory::getLogger('S5W'));
    }
}
```

```php
#[Autowired]
private S5w $s5w;
```

Several buckets are several named beans (`#[Bean(name: 's5w.media')]`, injected with
`#[Inject('s5w.media')]`).

---

## Uploading

```php
// into a folder, under a name of your own
$s5w->files()->upload($path, folder: 'docs', name: 'Contract 42.pdf');

// a file from a request: pass the original name, or the temporary one is stored
$s5w->files()->upload($upload['tmp_name'], folder: 'avatars', name: $upload['name']);

// resize and re-encode an image on the server
use Flytachi\Winter\SDK\S5w\Option\ImageOptions;

$file = $s5w->files()->upload($path, imageOptions: ImageOptions::webp(80)->fit(512, 512));
$file->processed->operations;   // ["resize:512x384", "encode:webp@80"]
```

| File size | How it is sent |
|---|---|
| up to 16 MiB | one `multipart` request |
| larger | chunks via `/v1/uploads`: retried, resumable, deduplicated by SHA-256 |

The threshold is `S5wConfig::$chunkThreshold`.

### Large files: progress and resume

```php
use Flytachi\Winter\SDK\S5w\Entity\UploadSession;
use Flytachi\Winter\SDK\S5w\Exception\UploadInterruptedException;

try {
    $file = $s5w->uploads()->send(
        '/backups/db.sql',
        folder: 'backups',
        onStart: fn(UploadSession $s) => $job->saveUploadId($s->id),
        onProgress: fn(int $sent, int $total) => printf("%d%%\n", $sent * 100 / $total),
    );
} catch (UploadInterruptedException $e) {
    // the network never came back; the session lives on the server for 24 h
    $file = $s5w->uploads()->resume($e->sessionId(), '/backups/db.sql');
}
```

A broken chunk is retried up to three times; before each retry the SDK asks the server how
many bytes it holds, so nothing is sent twice or lost. The step-by-step API —
`start()`, `append()`, `status()`, `complete()`, `abort()` — is there when the loop has to be
yours.

## Folders and files

```php
use Flytachi\Winter\SDK\S5w\Entity\Folder;
use Flytachi\Winter\SDK\S5w\Enum\{FileSort, Retention};

$s5w->folders()->create('tmp-exports', retention: Retention::WEEK);   // files delete themselves
$s5w->folders()->ensure('avatars', public: true);                     // served without a key

$page = $s5w->files()->list(folder: 'docs', search: 'invoice', sort: FileSort::SIZE);   // Page<File>
foreach ($s5w->files()->all(folder: Folder::ROOT) as $file) { /* every page, lazily */ }

$s5w->files()->move($file, 'archive');                        // or Folder::ROOT
$s5w->files()->update($file, 'Q4-final.pdf', 'archive');      // name and folder in one request
```

The folder decides who sees a file: a public folder serves it at `publicUrl` without a key.
**A file in the bucket root is public.**

## Errors

Everything the SDK throws extends `S5wException`; branch on the class, keep the message for logs.

```php
try {
    $s5w->files()->rename($file, 'report.pdf');
} catch (NotFoundException) {        // 404 — no such file
} catch (ConflictException) {        // 409 — the name is taken in the folder
} catch (ValidationException $e) {   // 422 — $e->errors(): per-field messages
} catch (TransportException) {       // the server did not answer — worth retrying later
}
```

| Exception | When |
|---|---|
| `TransportException` | no HTTP answer: DNS, refused connection, timeout |
| `ResponseException` | an answer, but not an S5W one (not JSON, a field missing) |
| `UnauthorizedException` · `ForbiddenException` | 401 · 403 — the key is unknown, disabled, expired, or `BASIC` |
| `NotFoundException` · `ConflictException` | 404 · 409 |
| `PayloadTooLargeException` · `ValidationException` | 413 (quota full) · 422 |
| `ServerException` | 5xx |
| `UploadInterruptedException` | chunked upload stopped after its retries — `resume()` it |
| `UploadUnconfirmedException` | the answer to `complete` was lost; the file may exist |

## Logging

Pass any PSR-3 logger. You get one `debug` line per request and the chunked-upload events
(retries as `warning`). Values are filled into the message by the SDK itself, so lines read
right with any logger. The key never reaches a log or a `var_dump()`.

```
GET v1/check → 200 (21 ms)
upload b3b53a23-… started: backup.sql, 100725660 bytes in chunks of 8388608
PATCH v1/uploads/b3b53a23-… → 200 (869 ms)
upload b3b53a23-… completed: backup.sql → file VVWEoDe7rh0FGbCx
```

---

## Status

The SDK covers checking the key, folders, files, uploads and reading small files into memory
(`contents()`). **Not in the SDK yet:** downloading to disk (`download()`) and temporary links
(`links()`). Until then use the
ready-made `privateUrl` / `publicUrl` of a `File`, or the
[delivery](https://winterframe.net/apps/s5w/delivery) and
[links](https://winterframe.net/apps/s5w/links) API directly.

## Documentation

Full documentation (Russian for now): **[winterframe.net/packages/sdk-s5w](https://winterframe.net/packages/sdk-s5w)**.
Every class and public method in the code carries an `@link` to its section.

| Guide | What's inside |
|---|---|
| [Installation](https://winterframe.net/packages/sdk-s5w/installation) | requirements, keys, plain PHP and Winter setup |
| [Quick start](https://winterframe.net/packages/sdk-s5w/quickstart) | one end-to-end scenario |
| [Mental model](https://winterframe.net/packages/sdk-s5w/mental-model) | one client = one bucket, snapshots, ids |
| [Winter integration](https://winterframe.net/packages/sdk-s5w/winter-integration) | beans, several buckets, logging, async |
| [Uploading files](https://winterframe.net/packages/sdk-s5w/uploading-files) | disk, request, memory, images, duplicates |
| [Large files](https://winterframe.net/packages/sdk-s5w/large-uploads) | progress, resume, deduplication |
| [Step-by-step uploads](https://winterframe.net/packages/sdk-s5w/low-level-uploads) | your own chunk loop |
| [Folders and files](https://winterframe.net/packages/sdk-s5w/organizing-files) | rules, search, move, rename, delete |
| [Error handling](https://winterframe.net/packages/sdk-s5w/error-handling) | what to catch, what to retry |
| [API reference](https://winterframe.net/packages/sdk-s5w/api-reference) | every method and argument |
| [Entities](https://winterframe.net/packages/sdk-s5w/entities) · [Options & enums](https://winterframe.net/packages/sdk-s5w/options-and-enums) · [Exceptions](https://winterframe.net/packages/sdk-s5w/exceptions) | fields, values, hierarchy |
| [Upload pipeline](https://winterframe.net/packages/sdk-s5w/upload-pipeline) · [HTTP & logging](https://winterframe.net/packages/sdk-s5w/http-and-logging) | how it works inside |

## License

MIT — [Flytachi](https://github.com/flytachi)
