<?php

declare(strict_types=1);

namespace Flytachi\Winter\SDK\S5w\Exception;

/**
 * 422 — the parameters do not add up: a field broke a rule, no such folder,
 * bad image options. {@see errors()} maps field names to the rules they broke.
 *
 * @link https://winterframe.net/packages/sdk-s5w/exceptions#validationexception ValidationException
 */
class ValidationException extends ApiException
{
    /**
     * @return list<string>
     *
     * @link https://winterframe.net/packages/sdk-s5w/exceptions#validationexception ValidationException
     */
    public function errorsOf(string $field): array
    {
        return $this->errors()[$field] ?? [];
    }
}
