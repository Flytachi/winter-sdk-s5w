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
 */
final readonly class Page implements \IteratorAggregate, \Countable
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

    public function hasNext(): bool
    {
        return $this->next !== null;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /** @return \ArrayIterator<int, T> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    public function count(): int
    {
        return count($this->items);
    }
}
