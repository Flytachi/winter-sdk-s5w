<?php

declare(strict_types=1);

namespace Flytachi\Winter\SDK\S5w\Support;

use Flytachi\Winter\SDK\S5w\Exception\ResponseException;

/**
 * A decoded JSON answer with typed, checked access.
 *
 * Entities read fields through this instead of `$data['x']`, so a missing or
 * mistyped field becomes one clear {@see ResponseException} naming the field
 * (`v1/check: bytes.quota must be int, got string`) instead of a TypeError
 * deep inside a constructor.
 *
 * @internal
 */
final readonly class Payload
{
    /** Timestamps s5w sends: `2026-08-17 18:22:04 +00:00`. */
    private const string TIME_FORMAT = 'Y-m-d H:i:s P';

    public function __construct(
        private array $data,
        private string $where = '',
        private string $prefix = '',
    ) {
    }

    /** @return array<array-key, mixed> */
    public function raw(): array
    {
        return $this->data;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function string(string $key): string
    {
        $v = $this->value($key);
        return is_string($v) ? $v : $this->fail($key, 'string', $v);
    }

    public function stringOrNull(string $key): ?string
    {
        $v = $this->data[$key] ?? null;
        return $v === null || is_string($v) ? $v : $this->fail($key, 'string|null', $v);
    }

    public function int(string $key): int
    {
        $v = $this->value($key);
        return is_int($v) ? $v : $this->fail($key, 'int', $v);
    }

    public function intOrNull(string $key): ?int
    {
        $v = $this->data[$key] ?? null;
        return $v === null || is_int($v) ? $v : $this->fail($key, 'int|null', $v);
    }

    public function bool(string $key): bool
    {
        $v = $this->value($key);
        return is_bool($v) ? $v : $this->fail($key, 'bool', $v);
    }

    /** A nested object. */
    public function object(string $key): self
    {
        $v = $this->value($key);
        return is_array($v) ? new self($v, $this->where, $this->path($key) . '.') : $this->fail($key, 'object', $v);
    }

    public function objectOrNull(string $key): ?self
    {
        $v = $this->data[$key] ?? null;
        if ($v === null) {
            return null;
        }
        return is_array($v)
            ? new self($v, $this->where, $this->path($key) . '.')
            : $this->fail($key, 'object|null', $v);
    }

    /**
     * A nested array of objects.
     *
     * @return list<self>
     */
    public function list(string $key): array
    {
        $v = $this->value($key);
        if (!is_array($v) || !array_is_list($v)) {
            $this->fail($key, 'array', $v);
        }

        return self::items($v, $this->where, $this->path($key));
    }

    /**
     * The payload itself as a list of objects (an endpoint that answers `[ … ]`).
     *
     * @return list<self>
     */
    public function asList(): array
    {
        if (!array_is_list($this->data)) {
            throw new ResponseException("S5W {$this->where}: expected a JSON array");
        }

        return self::items($this->data, $this->where, rtrim($this->prefix, '.'));
    }

    public function time(string $key): \DateTimeImmutable
    {
        return $this->parseTime($key, $this->string($key));
    }

    public function timeOrNull(string $key): ?\DateTimeImmutable
    {
        $v = $this->stringOrNull($key);
        return $v === null || $v === '' ? null : $this->parseTime($key, $v);
    }

    /**
     * An int-backed enum sent as `{ "id": 2, "name": "FULL" }`.
     *
     * @template T of \BackedEnum
     * @param class-string<T> $enum
     * @return T
     */
    public function enum(string $key, string $enum): \BackedEnum
    {
        $id = $this->object($key)->int('id');
        return $enum::tryFrom($id) ?? throw new ResponseException(
            "S5W {$this->where}: {$this->path($key)} = {$id} is not a known " . $enum,
        );
    }

    /**
     * Same as {@see enum()}, for a field that may be `null`.
     *
     * @template T of \BackedEnum
     * @param class-string<T> $enum
     * @return T|null
     */
    public function enumOrNull(string $key, string $enum): ?\BackedEnum
    {
        return ($this->data[$key] ?? null) === null ? null : $this->enum($key, $enum);
    }

    /** @return list<self> */
    private static function items(array $list, string $where, string $path): array
    {
        $items = [];
        foreach ($list as $i => $item) {
            if (!is_array($item)) {
                throw new ResponseException("S5W {$where}: {$path}[{$i}] must be an object");
            }
            $items[] = new self($item, $where, "{$path}[{$i}].");
        }

        return $items;
    }

    private function parseTime(string $key, string $value): \DateTimeImmutable
    {
        $time = \DateTimeImmutable::createFromFormat(self::TIME_FORMAT, $value);
        if ($time === false) {
            try {
                $time = new \DateTimeImmutable($value);
            } catch (\Exception) {
                throw new ResponseException("S5W {$this->where}: {$this->path($key)} = '{$value}' is not a time");
            }
        }

        return $time;
    }

    private function value(string $key): mixed
    {
        if (!array_key_exists($key, $this->data)) {
            throw new ResponseException("S5W {$this->where}: {$this->path($key)} is missing");
        }

        return $this->data[$key];
    }

    private function path(string $key): string
    {
        return $this->prefix . $key;
    }

    private function fail(string $key, string $expected, mixed $got): never
    {
        throw new ResponseException(
            "S5W {$this->where}: {$this->path($key)} must be {$expected}, got " . get_debug_type($got),
        );
    }
}
