<?php

namespace Flytachi\Winter\SDK\S5w;

use Flytachi\Winter\Cast\Common\CastClient;
use Flytachi\Winter\Cast\Common\CastHeader;
use Flytachi\Winter\Cast\Common\CastRequest;
use Flytachi\Winter\Cast\Common\CastResponse;
use Flytachi\Winter\Cast\Exception\RequestException;
use Flytachi\Winter\Cast\Stereotype\LoggingMiddleware;
use Flytachi\Winter\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class S5w
{
    private readonly string $baseUrl;
    private readonly string $apiKey;
    private readonly ?string $bucketUid;
    private LoggerInterface $logger;

    public function __construct(
        string $baseUrl,
        string $apiKey,
        ?string $bucketUid = null,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = trim($apiKey);
        $this->bucketUid = empty($bucketUid) ? null : trim($bucketUid);
        $this->logger = LoggerFactory::getLogger('S5W');
    }


    public function check(): array
    {
        $response = $this->send(
            CastRequest::get($this->baseUrl . '/v1/check')
                ->withHeaders($this->headers())
        );

        return $response;
    }


    private function send(CastRequest $request): mixed
    {
        try {
            $resp = $request->send($this->client());
            return $this->tryResult($resp);
        } catch (RequestException $ex) {
            throw new S5wException('S5W: ' . $ex->getMessage(), $ex->getCode(), $ex);
        }
    }

    private function client(): CastClient
    {
        return new CastClient()
            ->addMiddleware(new LoggingMiddleware(
                logger: $this->logger,
                requestLevel: LogLevel::DEBUG,
                responseLevel: LogLevel::DEBUG,
            ));
    }

    private function headers(): CastHeader
    {
        return new CastHeader()
            ->authBearer($this->apiKey)
            ->json();
    }

    protected function tryResult(CastResponse $response, ?string $dataKey = null): mixed
    {
        $body = $response->json();

        if (!$response->isSuccess()) {
            $message = $this->extractErrorMessage($body, $response->statusCode);
            throw new RequestException($response, $message);
        }

        // Return specific key if exists, otherwise return full body
        if ($dataKey !== null && is_array($body)) {
            return $body[$dataKey] ?? null;
        }

        return $body;
    }

    protected function extractErrorMessage(?array $body, int $statusCode): string
    {
        if ($body !== null) {
            // Common error message fields
            foreach (['message', 'error', 'error_message', 'msg', 'detail'] as $key) {
                if (isset($body[$key]) && is_string($body[$key])) {
                    return $body[$key];
                }
            }

            // Nested error object
            if (isset($body['error']) && is_array($body['error'])) {
                if (isset($body['error']['message'])) {
                    return $body['error']['message'];
                }
            }
        }

        return "HTTP Error {$statusCode}";
    }
}
