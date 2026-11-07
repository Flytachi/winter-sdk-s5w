<?php

declare(strict_types=1);

namespace Flytachi\Winter\SDK\S5w\Entity;

use Flytachi\Winter\SDK\S5w\Support\Payload;

/**
 * One page of a list: `{ "meta": …, "data": [ … ] }`.
 *
 * Iterable and countable over the items of this page:
 * ```
 * foreach ($s5w->folders()->list() as $folder) { … }
 * ```
 *
 * @template T
 * @implements \IteratorAggregate<int, T>
 * @link https://winterframe.net/packages/sdk-s5w/entities#page Page
 */
final readonly class Page implements \IteratorAggregate, \Countable, \JsonSerializable
{
    /**
     * @param list<T> $items
     */
    public function __construct(
        public array $items,
        /** This page's number, from 1. */
        public int $current,
        /** Page size the server applied. */
        public int $size,
        /** Items across all pages. */
        public int $total,
        public int $pages,
        public ?int $previous,
        public ?int $next,
    ) {
    }

    /**
     * @template E
     * @param callable(Payload): E $map
     * @return self<E>
     * @internal
     */
    public static function fromPayload(Payload $p, callable $map): self
    {
        $meta = $p->object('meta');

        return new self(
            items: array_map($map, $p->list('data')),
            current: $meta->int('current'),
            size: $meta->int('size'),
            total: $meta->int('total'),
            pages: $meta->int('pages'),
            previous: $meta->intOrNull('previous'),
            next: $meta->intOrNull('next'),
        );
    }

    /** @link https://winterframe.net/packages/sdk-s5w/entities#page Page */
    public function hasNext(): bool
    {
        return $this->next !== null;
    }

    /** @link https://winterframe.net/packages/sdk-s5w/entities#page Page */
    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * @return \ArrayIterator<int, T>
     *
     * @link https://winterframe.net/packages/sdk-s5w/entities#page Page
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    /** @link https://winterframe.net/packages/sdk-s5w/entities#page Page */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * A new page with every item passed through `$fn` — e.g. into your own DTOs.
     * The meta is kept.
     *
     * @template R
     * @param callable(T): R $fn
     * @return self<R>
     * @link https://winterframe.net/packages/sdk-s5w/entities#page Page
     */
    public function map(callable $fn): self
    {
        return new self(
            array_map($fn, $this->items),
            $this->current,
            $this->size,
            $this->total,
            $this->pages,
            $this->previous,
            $this->next,
        );
    }

    /**
     * The server's own shape: `{ "meta": { … }, "data": [ … ] }`. Items that have
     * `toArray()` are converted; anything else is left for `json_encode`.
     *
     * @return array{meta: array<string, int|null>, data: list<mixed>}
     */
    public function toArray(): array
    {
        return [
            'meta' => [
                'current' => $this->current,
                'size' => $this->size,
                'total' => $this->total,
                'pages' => $this->pages,
                'previous' => $this->previous,
                'next' => $this->next,
            ],
            'data' => array_map(
                static fn(mixed $item) => is_object($item) && method_exists($item, 'toArray')
                    ? $item->toArray()
                    : $item,
                $this->items,
            ),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
