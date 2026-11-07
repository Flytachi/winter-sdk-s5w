<?php

declare(strict_types=1);

namespace Flytachi\Winter\SDK\S5w\Http;

use Composer\InstalledVersions;
use Flytachi\Winter\Cast\Common\CastClient;
use Flytachi\Winter\Cast\Common\CastHeader;
use Flytachi\Winter\Cast\Common\CastRequest;
use Flytachi\Winter\Cast\Common\CastResponse;
use Flytachi\Winter\Cast\Exception\CastException;
use Flytachi\Winter\SDK\S5w\Exception\ApiException;
use Flytachi\Winter\SDK\S5w\Exception\ResponseException;
use Flytachi\Winter\SDK\S5w\Exception\TransportException;
use Flytachi\Winter\SDK\S5w\S5wConfig;
use Flytachi\Winter\SDK\S5w\Support\InterpolatingLogger;
use Flytachi\Winter\SDK\S5w\Support\Payload;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The one place that talks HTTP: base URL, the key, timeouts, retries, logging,
 * and turning an error status into a typed {@see ApiException}.
 *
 * Resources build paths and bodies; they never see cURL or a status code.
 *
 * @internal Used by the SDK's resources; the public surface is {@see \Flytachi\Winter\SDK\S5w\S5w}.
 */
final class HttpClient
{
    private const string PACKAGE = 'flytachi/winter-sdk-s5w';

    /** Built once per process: `WinterSDK/1.0.0`. */
    private static ?string $userAgent = null;

    private readonly CastClient $client;

    private readonly LoggerInterface $logger;

    /**
     * @param CastClient|null      $client Bring your own to add middleware or cURL defaults
     *                                     (e.g. winter-cast's LoggingMiddleware for a full HTTP trace).
     * @param LoggerInterface|null $logger Gets one debug line per request and the SDK's own events;
     *                                     null — nothing is logged.
     */
    public function __construct(
        private readonly S5wConfig $config,
        ?CastClient $client = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->client = $client ?? new CastClient($config->timeout, $config->connectTimeout);
        $this->logger = $logger === null ? new NullLogger() : new InterpolatingLogger($logger);
    }

    public function config(): S5wConfig
    {
        return $this->config;
    }

    /** For the SDK's own events (an upload started, a chunk retried); a no-op without a logger. */
    public function logger(): LoggerInterface
    {
        return $this->logger;
    }

    /** Absolute URL of a path on the instance. */
    public function url(string $path): string
    {
        return $this->config->baseUrl . '/' . ltrim($path, '/');
    }

    /**
     * A JSON call: sends `$body` as JSON (if given) and decodes the JSON answer.
     *
     * @param array<string, scalar|null> $query  null values are dropped
     */
    public function json(string $method, string $path, ?array $body = null, array $query = []): Payload
    {
        $request = $this->request($method, $path, $query);
        if ($body !== null) {
            $request = $request->withJsonBody($body);
        }

        return $this->sendJson($request, $method, $path);
    }

    /**
     * A call whose success has no body (204), e.g. a delete.
     *
     * @param array<string, scalar|null> $query
     */
    public function call(string $method, string $path, array $query = []): void
    {
        $this->send($this->request($method, $path, $query), $method, $path);
    }

    /**
     * A request with the key, the timeouts and the retries already on it —
     * for the calls that need to shape the body themselves (multipart, raw bytes).
     *
     * @param array<string, scalar|null> $query
     * @param array<string, string>      $headers extra headers on top of the SDK's own
     */
    public function request(string $method, string $path, array $query = [], array $headers = []): CastRequest
    {
        $query = array_filter($query, static fn($v) => $v !== null && $v !== '');
        $url = $this->url($path);

        $request = match (strtoupper($method)) {
            'GET' => CastRequest::get($url, $query ?: null),
            'POST' => CastRequest::post($url, $query ?: null),
            'PUT' => CastRequest::put($url, $query ?: null),
            'PATCH' => CastRequest::patch($url, $query ?: null),
            'DELETE' => CastRequest::delete($url, $query ?: null),
            'HEAD' => CastRequest::head($url, $query ?: null),
            default => throw new \InvalidArgumentException("Unsupported HTTP method {$method}"),
        };

        $head = CastHeader::instance()
            ->authBearer($this->config->apiKey)
            ->set('Accept', 'application/json')
            ->userAgent($this->config->userAgent ?? self::userAgent());
        foreach ($headers as $key => $value) {
            $head->set($key, $value);
        }

        return $request
            ->withHeaders($head)
            ->timeout($this->config->timeout)
            ->connectTimeout($this->config->connectTimeout)
            ->retry($this->config->retries);
    }

    /**
     * {@see request()} for a call that moves file bytes: the transfer timeout and
     * no retries — a retried upload whose first attempt did land stores the file twice.
     *
     * @param array<string, scalar|null> $query
     * @param array<string, string>      $headers
     */
    public function transferRequest(
        string $method,
        string $path,
        array $query = [],
        array $headers = [],
    ): CastRequest {
        return $this->request($method, $path, $query, $headers)
            ->timeout($this->config->transferTimeout)
            ->retry(1);
    }

    /** Sends a prepared request and decodes its JSON answer. */
    public function sendJson(CastRequest $request, string $method, string $path): Payload
    {
        return $this->decode($this->send($request, $method, $path), $method, $path);
    }

    /**
     * Sends and returns the raw response if the status is a success; otherwise throws.
     *
     * Logs one debug line per request — `GET v1/check → 200 (21 ms)` — never headers,
     * so the key stays out. Failures are not logged as errors here: they are thrown, and
     * the caller decides.
     */
    public function send(CastRequest $request, string $method, string $path): CastResponse
    {
        $started = hrtime(true);

        try {
            $response = $request->send($this->client);
        } catch (CastException $e) {
            $this->logger->debug('{method} {path} → failed ({ms} ms): {error}', [
                'method' => $method,
                'path' => rawurldecode($path),
                'ms' => self::elapsed($started),
                'error' => $e->getMessage(),
            ]);
            throw new TransportException("S5W {$path}: {$e->getMessage()}", (int) $e->getCode(), $e);
        }

        $this->logger->debug('{method} {path} → {status} ({ms} ms)', [
            'method' => $method,
            'path' => rawurldecode($path),
            'status' => $response->statusCode,
            'ms' => self::elapsed($started),
        ]);

        if ($response->isSuccess()) {
            return $response;
        }

        $body = $response->json();
        $message = is_string($body['message'] ?? null) ? $body['message'] : "HTTP {$response->statusCode}";
        $errors = is_array($body['errors'] ?? null) ? self::errors($body['errors']) : [];

        throw ApiException::from($response->statusCode, $message, $errors, $method, $path);
    }

    private function decode(CastResponse $response, string $method, string $path): Payload
    {
        $body = $response->body();
        if ($body === null || $body === '') {
            return new Payload([], $path);
        }

        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ResponseException("S5W {$path}: the answer is not JSON", 0, $e);
        }
        if (!is_array($data)) {
            throw new ResponseException("S5W {$path}: expected a JSON object or array");
        }

        return new Payload($data, $path);
    }

    /** @return array<string, list<string>> */
    private static function errors(array $raw): array
    {
        $errors = [];
        foreach ($raw as $field => $messages) {
            $errors[(string) $field] = array_values(array_map('strval', (array) $messages));
        }

        return $errors;
    }

    /**
     * The default User-Agent, `WinterSDK/{version}`. The version comes from Composer:
     * the installed tag, `dev-main` for a path repository.
     */
    private static function userAgent(): string
    {
        if (self::$userAgent !== null) {
            return self::$userAgent;
        }

        $version = class_exists(InstalledVersions::class) && InstalledVersions::isInstalled(self::PACKAGE)
            ? (InstalledVersions::getPrettyVersion(self::PACKAGE) ?? 'dev')
            : 'dev';
        return self::$userAgent = "WinterSDK/{$version}";
    }

    /** Milliseconds since an `hrtime(true)` mark. */
    private static function elapsed(int|float $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
