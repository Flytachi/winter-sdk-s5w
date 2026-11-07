<?php

declare(strict_types=1);

namespace Flytachi\Winter\SDK\S5w;

/**
 * Where an s5w instance answers and which key to use there.
 *
 * Immutable: the `with*()` methods return a copy. One config = one key = one bucket —
 * the bucket is decided by the key on the server, so it is not configured here.
 *
 * ```
 * $config = new S5wConfig('https://s5.example.com', 's5w_9f3c…');
 * $config = $config->withTimeout(30)->withRetries(3);
 * ```
 */
final readonly class S5wConfig
{
    public string $baseUrl;
    public string $apiKey;

    /**
     * @param string $baseUrl         Instance address with the scheme, e.g. `https://s5.example.com`.
     * @param string $apiKey          The bucket key issued in the panel, `s5w_…`.
     * @param int    $timeout         Seconds for an ordinary API call.
     * @param int    $connectTimeout  Seconds to establish the connection.
     * @param int    $retries         Attempts on a transport failure (connection refused, timeout).
     *                                1 = no retry. Never applied to uploads: a retried upload
     *                                whose first attempt did land would store the file twice.
     * @param int    $transferTimeout Seconds for a call that moves file bytes (upload, download).
     * @param int    $chunkThreshold  Files larger than this are uploaded in chunks (resumable)
     *                                instead of one request. At most {@see Api\Files::SINGLE_REQUEST_LIMIT}.
     * @param string|null $userAgent  Replaces the default `WinterSDK/{version}`.
     */
    public function __construct(
        string $baseUrl,
        string $apiKey,
        public int $timeout = 15,
        public int $connectTimeout = 5,
        public int $retries = 1,
        public int $transferTimeout = 300,
        public int $chunkThreshold = 16 * 1024 * 1024,
        public ?string $userAgent = null,
    ) {
        $baseUrl = rtrim(trim($baseUrl), '/');
        $apiKey = trim($apiKey);

        if ($baseUrl === '' || !preg_match('~^https?://~i', $baseUrl)) {
            throw new S5wException("S5W: base URL must start with http:// or https://, got '{$baseUrl}'");
        }
        if ($apiKey === '') {
            throw new S5wException('S5W: API key is empty');
        }
        if ($timeout < 1 || $connectTimeout < 1 || $retries < 1 || $transferTimeout < 1) {
            throw new S5wException('S5W: timeouts and retries must be positive');
        }
        if ($chunkThreshold < 1 || $chunkThreshold > Api\Files::SINGLE_REQUEST_LIMIT) {
            throw new S5wException(
                'S5W: chunkThreshold must be between 1 and ' . Api\Files::SINGLE_REQUEST_LIMIT . ' bytes',
            );
        }

        $this->baseUrl = $baseUrl;
        $this->apiKey = $apiKey;
    }

    public function withTimeout(int $seconds, ?int $connectSeconds = null): self
    {
        return $this->copy(timeout: $seconds, connectTimeout: $connectSeconds ?? $this->connectTimeout);
    }

    public function withTransferTimeout(int $seconds): self
    {
        return $this->copy(transferTimeout: $seconds);
    }

    public function withChunkThreshold(int $bytes): self
    {
        return $this->copy(chunkThreshold: $bytes);
    }

    public function withRetries(int $attempts): self
    {
        return $this->copy(retries: $attempts);
    }

    public function withUserAgent(string $userAgent): self
    {
        return $this->copy(userAgent: $userAgent);
    }

    /** The key is a secret: never let it reach a dump or a log. */
    public function __debugInfo(): array
    {
        return [
            'baseUrl' => $this->baseUrl,
            'apiKey' => substr($this->apiKey, 0, 4) . '…' . substr($this->apiKey, -4),
            'timeout' => $this->timeout,
            'connectTimeout' => $this->connectTimeout,
            'retries' => $this->retries,
            'transferTimeout' => $this->transferTimeout,
            'chunkThreshold' => $this->chunkThreshold,
            'userAgent' => $this->userAgent,
        ];
    }

    private function copy(
        ?int $timeout = null,
        ?int $connectTimeout = null,
        ?int $retries = null,
        ?int $transferTimeout = null,
        ?int $chunkThreshold = null,
        ?string $userAgent = null,
    ): self {
        return new self(
            $this->baseUrl,
            $this->apiKey,
            $timeout ?? $this->timeout,
            $connectTimeout ?? $this->connectTimeout,
            $retries ?? $this->retries,
            $transferTimeout ?? $this->transferTimeout,
            $chunkThreshold ?? $this->chunkThreshold,
            $userAgent ?? $this->userAgent,
        );
    }
}
