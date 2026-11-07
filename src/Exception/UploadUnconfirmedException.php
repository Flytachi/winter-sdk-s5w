<?php

declare(strict_types=1);

namespace Flytachi\Winter\SDK\S5w\Exception;

use Flytachi\Winter\SDK\S5w\S5wException;

/**
 * Every byte was sent and `complete` was requested, but no answer came back (a broken
 * connection, a gateway error) — and the session is gone. The file may have been
 * created; if it was, its id is unknown.
 *
 * The SDK does not guess (searching by name could pick someone else's file).
 * Look the file up yourself, or upload again if a duplicate is acceptable.
 *
 * @link https://winterframe.net/packages/sdk-s5w/exceptions#uploadunconfirmedexception UploadUnconfirmedException
 */
class UploadUnconfirmedException extends S5wException
{
    public function __construct(
        private readonly string $sessionId,
        private readonly string $name,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            "S5W: upload {$sessionId} of '{$name}' was completed but not confirmed: "
                . 'the file may have been created, its id is unknown',
            0,
            $previous,
        );
    }

    /**
     * @link https://winterframe.net/packages/sdk-s5w/exceptions#uploadunconfirmedexception UploadUnconfirmedException
     */
    public function sessionId(): string
    {
        return $this->sessionId;
    }

    /**
     * The name the upload was started with (the stored one may differ, e.g. `name (1).ext`).
     *
     * @link https://winterframe.net/packages/sdk-s5w/exceptions#uploadunconfirmedexception UploadUnconfirmedException
     */
    public function name(): string
    {
        return $this->name;
    }
}
