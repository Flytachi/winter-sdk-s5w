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
 * $config = $config->withTimeout(60)->withRetries(3);
 * ```
 */
final readonly class S5wConfig
{
    public string $baseUrl;
    public string $apiKey;

    /**
     * @param string $baseUrl        Instance address with the scheme, e.g. `https://s5.example.com`.
     * @param string $apiKey         The bucket key issued in the panel, `s5w_…`.
     * @param int    $timeout        Seconds for an ordinary API call (uploads/downloads set their own).
     * @param int    $connectTimeout Seconds to establish the connection.
     * @param int    $retries        Attempts on a transport failure (connection refused, timeout). 1 = no retry.
     * @param string|null $userAgent Overrides the default `winter-sdk-s5w` User-Agent.
     */
    public function __construct(
        string $baseUrl,
        string $apiKey,
        public int $timeout = 15,
        public int $connectTimeout = 5,
        public int $retries = 1,
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
        if ($timeout < 1 || $connectTimeout < 1 || $retries < 1) {
            throw new S5wException('S5W: timeout, connectTimeout and retries must be positive');
        }

        $this->baseUrl = $baseUrl;
        $this->apiKey = $apiKey;
    }

    public function withTimeout(int $seconds, ?int $connectSeconds = null): self
    {
        return new self(
            $this->baseUrl,
            $this->apiKey,
            $seconds,
            $connectSeconds ?? $this->connectTimeout,
            $this->retries,
            $this->userAgent,
        );
    }

    public function withRetries(int $attempts): self
    {
        return new self(
            $this->baseUrl,
            $this->apiKey,
            $this->timeout,
            $this->connectTimeout,
            $attempts,
            $this->userAgent,
        );
    }

    public function withUserAgent(string $userAgent): self
    {
        return new self(
            $this->baseUrl,
            $this->apiKey,
            $this->timeout,
            $this->connectTimeout,
            $this->retries,
            $userAgent,
        );
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
            'userAgent' => $this->userAgent,
        ];
    }
}
