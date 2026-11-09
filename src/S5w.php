<?php

declare(strict_types=1);

namespace Flytachi\Winter\SDK\S5w;

use Flytachi\Winter\Cast\Client\CastClient;
use Flytachi\Winter\SDK\S5w\Api\Files;
use Flytachi\Winter\SDK\S5w\Api\Folders;
use Flytachi\Winter\SDK\S5w\Api\Uploads;
use Flytachi\Winter\SDK\S5w\Exception\ApiException;
use Flytachi\Winter\SDK\S5w\Exception\TransportException;
use Flytachi\Winter\SDK\S5w\Http\HttpClient;
use Flytachi\Winter\SDK\S5w\Entity\KeyInfo;
use Psr\Log\LoggerInterface;

/**
 * Client of one s5w bucket — the entry point of the SDK.
 *
 * One instance = one key = one bucket. Stateless and safe to share: build it once
 * (a DI singleton) and reuse it everywhere.
 *
 * ```
 * $s5w = new S5w(new S5wConfig('https://s5.example.com', 's5w_9f3c…'));
 *
 * $key = $s5w->check();
 * $key->bucket->name;         // "media"
 * $key->storage->free;        // 467587072
 * ```
 *
 * The API groups hang off it as resources, created on first use:
 * `folders()`, `files()`, `uploads()`; `links()` is coming next.
 *
 * @link https://winterframe.net/packages/sdk-s5w/api-reference#s5w S5w
 */
final class S5w
{
    private readonly HttpClient $http;

    private ?Folders $folders = null;

    private ?Files $files = null;

    private ?Uploads $uploads = null;

    /**
     * @param CastClient|null      $client Custom HTTP client (own middleware, proxies, cURL options).
     * @param LoggerInterface|null $logger One debug line per request (method, path, status, time)
     *                                     and the SDK's own events (uploads: start, retries, end).
     *                                     Never the key.
     *
     * @link https://winterframe.net/packages/sdk-s5w/api-reference#s5w__construct S5w::__construct()
     */
    public function __construct(
        S5wConfig $config,
        ?CastClient $client = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->http = new HttpClient($config, $client, $logger);
    }

    /**
     * Shortcut for the common case.
     *
     * @link https://winterframe.net/packages/sdk-s5w/api-reference#s5wconnect S5w::connect()
     */
    public static function connect(string $baseUrl, string $apiKey, ?LoggerInterface $logger = null): self
    {
        return new self(new S5wConfig($baseUrl, $apiKey), logger: $logger);
    }

    /** @link https://winterframe.net/packages/sdk-s5w/api-reference#s5w-config S5w->config() */
    public function config(): S5wConfig
    {
        return $this->http->config();
    }

    /**
     * `/v1/folders` — needs a FULL key.
     *
     * @link https://winterframe.net/packages/sdk-s5w/api-reference#s5wfolders-s5wfiles-s5wuploads S5w::folders()
     */
    public function folders(): Folders
    {
        return $this->folders ??= new Folders($this->http);
    }

    /**
     * `/v1/files` — needs a FULL key.
     *
     * @link https://winterframe.net/packages/sdk-s5w/api-reference#s5wfolders-s5wfiles-s5wuploads S5w::files()
     */
    public function files(): Files
    {
        return $this->files ??= new Files($this->http, $this->uploads());
    }

    /**
     * `/v1/uploads` — chunked, resumable uploads; needs a FULL key.
     *
     * @link https://winterframe.net/packages/sdk-s5w/api-reference#s5wfolders-s5wfiles-s5wuploads S5w::uploads()
     */
    public function uploads(): Uploads
    {
        return $this->uploads ??= new Uploads($this->http);
    }

    /**
     * Is this key still good, and what is behind it?
     *
     * Works with a read-only key too. Call it at start-up and from a health check:
     * it tells "the key died" (an {@see ApiException}) from "the service is down"
     * (a {@see TransportException}).
     *
     * @throws Exception\UnauthorizedException the key is unknown
     * @throws Exception\ForbiddenException    the key is disabled or expired
     * @throws TransportException              the service was not reached
     * @link https://winterframe.net/packages/sdk-s5w/api-reference#s5wcheck S5w::check()
     */
    public function check(): KeyInfo
    {
        return KeyInfo::fromPayload($this->http->json('GET', 'v1/check'));
    }

    /**
     * {@see check()} without the exception: true when the key works.
     *
     * A transport failure still throws — "can't tell" is not "no".
     *
     * @link https://winterframe.net/packages/sdk-s5w/api-reference#s5wisvalid S5w::isValid()
     */
    public function isValid(): bool
    {
        try {
            $this->check();
            return true;
        } catch (Exception\UnauthorizedException | Exception\ForbiddenException) {
            return false;
        }
    }
}
