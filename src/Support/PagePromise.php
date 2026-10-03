<?php

declare(strict_types=1);

namespace XaiOfficial\Sdk\Support;

/**
 * @template TPage
 * @template TItem
 * @implements \IteratorAggregate<int, TItem>
 */
final class PagePromise implements \IteratorAggregate
{
    /** @var callable(): TPage */
    private $first;

    /** @var callable(TPage): (TPage|null) */
    private $next;

    /** @var callable(TPage): array<int, TItem> */
    private $items;

    private mixed $resolvedPage = null;

    /**
     * @param callable(): TPage $first
     * @param callable(TPage): (TPage|null) $next
     * @param callable(TPage): array<int, TItem> $items
     */
    public function __construct(callable $first, callable $next, callable $items)
    {
        $this->first = $first;
        $this->next = $next;
        $this->items = $items;
    }

    /**
     * @return TPage
     */
    public function first(): mixed
    {
        if ($this->resolvedPage === null) {
            $this->resolvedPage = ($this->first)();
        }

        return $this->resolvedPage;
    }

    public function getIterator(): \Traversable
    {
        $page = $this->first();
        while ($page !== null) {
            foreach (($this->items)($page) as $item) {
                yield $item;
            }
            $page = ($this->next)($page);
        }
    }

    /**
     * @template TQuery
     * @template TPageOf
     * @template TItemOf
     * @param TQuery $query
     * @param callable(TQuery): TPageOf $fetchPage
     * @param callable(TPageOf): array<int, TItemOf> $items
     * @return PagePromise<TPageOf, TItemOf>
     */
    public static function tokenPages(array $query, callable $fetchPage, callable $items): self
    {
        return new self(
            fn () => $fetchPage($query),
            fn ($page) => ! empty($page['pagination_token'])
                ? $fetchPage(array_merge($query, ['pagination_token' => $page['pagination_token']]))
                : null,
            $items,
        );
    }
}
