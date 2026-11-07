<?php

declare(strict_types=1);

namespace Flytachi\Winter\SDK\S5w\Http;

use Flytachi\Winter\Cast\Common\CastClient;
use Flytachi\Winter\Cast\Common\CastHeader;
use Flytachi\Winter\Cast\Common\CastRequest;
use Flytachi\Winter\Cast\Common\CastResponse;
use Flytachi\Winter\Cast\Exception\CastException;
use Flytachi\Winter\Cast\Stereotype\LoggingMiddleware;
use Flytachi\Winter\SDK\S5w\Exception\ApiException;
use Flytachi\Winter\SDK\S5w\Exception\ResponseException;
use Flytachi\Winter\SDK\S5w\Exception\TransportException;
use Flytachi\Winter\SDK\S5w\S5wConfig;
use Flytachi\Winter\SDK\S5w\Support\Payload;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

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
    public const string VERSION = '0.1.0';

    private readonly CastClient $client;

    /**
     * @param CastClient|null $client Bring your own to add middleware or cURL defaults.
     *                                Logging is added only to the client the SDK builds itself.
     */
    public function __construct(
        private readonly S5wConfig $config,
        ?CastClient $client = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->client = $client ?? self::defaultClient($config, $logger);
    }

    public function config(): S5wConfig
    {
        return $this->config;
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

        return $this->decode($this->send($request, $method, $path), $method, $path);
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
     */
    public function request(string $method, string $path, array $query = []): CastRequest
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

        return $request
            ->withHeaders(
                CastHeader::instance()
                    ->authBearer($this->config->apiKey)
                    ->set('Accept', 'application/json')
                    ->userAgent($this->config->userAgent ?? 'winter-sdk-s5w/' . self::VERSION)
            )
            ->timeout($this->config->timeout)
            ->connectTimeout($this->config->connectTimeout)
            ->retry($this->config->retries);
    }

    /**
     * Sends and returns the raw response if the status is a success; otherwise throws.
     */
    public function send(CastRequest $request, string $method, string $path): CastResponse
    {
        try {
            $response = $request->send($this->client);
        } catch (CastException $e) {
            throw new TransportException("S5W {$method} {$path}: {$e->getMessage()}", (int) $e->getCode(), $e);
        }

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
            return new Payload([], "{$method} {$path}");
        }

        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ResponseException("S5W {$method} {$path}: the answer is not JSON", 0, $e);
        }
        if (!is_array($data)) {
            throw new ResponseException("S5W {$method} {$path}: expected a JSON object or array");
        }

        return new Payload($data, "{$method} {$path}");
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

    private static function defaultClient(S5wConfig $config, ?LoggerInterface $logger): CastClient
    {
        $client = new CastClient($config->timeout, $config->connectTimeout);
        if ($logger !== null) {
            // Logs method, URL, status and timing — never headers, so the key stays out.
            $client->addMiddleware(new LoggingMiddleware(
                logger: $logger,
                requestLevel: LogLevel::DEBUG,
                responseLevel: LogLevel::DEBUG,
                errorLevel: LogLevel::WARNING,
            ));
        }

        return $client;
    }
}
