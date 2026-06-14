<?php
declare(strict_types=1);

namespace core;

/**
 * 集合类 - 参考 Laravel Collection
 * 提供流畅的数组操作链式调用
 */
class Collection implements \IteratorAggregate, \ArrayAccess, \Countable, \JsonSerializable
{
    /** @var array<int|string, mixed> */
    protected array $items;

    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    public static function make(array $items = []): self
    {
        return new static($items);
    }

    public function all(): array
    {
        return $this->items;
    }

    public function count(): int
    {
        return count($this->items);
    }

    // ─── 过滤与映射 ───

    public function map(callable $callback): self
    {
        $keys = array_keys($this->items);
        $values = array_map($callback, $this->items, $keys);
        return new static(empty($keys) ? [] : array_combine($keys, $values));
    }

    public function filter(?callable $callback = null): self
    {
        if ($callback === null) {
            return new static(array_filter($this->items));
        }
        return new static(array_filter($this->items, $callback, ARRAY_FILTER_USE_BOTH));
    }

    public function reject(callable $callback): self
    {
        return $this->filter(fn($item, $key) => !$callback($item, $key));
    }

    public function where(string $key, mixed $value): self
    {
        return $this->filter(fn($item) => ($item[$key] ?? null) === $value);
    }

    public function whereIn(string $key, array $values): self
    {
        return $this->filter(fn($item) => in_array($item[$key] ?? null, $values, true));
    }

    public function pluck(string $value, ?string $key = null): self
    {
        $results = [];
        foreach ($this->items as $item) {
            $itemValue = $item[$value] ?? null;
            if ($key !== null) {
                $results[$item[$key] ?? '__pluck_key_' . count($results)] = $itemValue;
            } else {
                $results[] = $itemValue;
            }
        }
        return new static($results);
    }

    public function only(array $keys): self
    {
        return new static(array_intersect_key($this->items, array_flip($keys)));
    }

    public function except(array $keys): self
    {
        return new static(array_diff_key($this->items, array_flip($keys)));
    }

    // ─── 聚合 ───

    public function sum(?string $key = null): float|int
    {
        if ($key === null) {
            return array_sum($this->items);
        }
        return array_sum(array_column($this->items, $key));
    }

    public function avg(?string $key = null): float|int
    {
        $count = $this->count();
        if ($count === 0) return 0;
        return $this->sum($key) / $count;
    }

    public function min(?string $key = null): mixed
    {
        if ($key === null) {
            return empty($this->items) ? null : min($this->items);
        }
        $values = array_column($this->items, $key);
        return empty($values) ? null : min($values);
    }

    public function max(?string $key = null): mixed
    {
        if ($key === null) {
            return empty($this->items) ? null : max($this->items);
        }
        $values = array_column($this->items, $key);
        return empty($values) ? null : max($values);
    }

    // ─── 排序 ───

    public function sort(?callable $callback = null): self
    {
        $items = $this->items;
        if ($callback === null) {
            asort($items);
        } else {
            uasort($items, $callback);
        }
        return new static($items);
    }

    public function sortBy(string $key, int $options = SORT_REGULAR): self
    {
        $items = $this->items;
        uasort($items, function ($a, $b) use ($key, $options) {
            $va = $a[$key] ?? null;
            $vb = $b[$key] ?? null;
            if ($options === SORT_STRING) {
                return strcmp((string)$va, (string)$vb);
            }
            if ($options === SORT_NUMERIC) {
                return (float)$va <=> (float)$vb;
            }
            return $va <=> $vb;
        });
        return new static($items);
    }

    public function sortByDesc(string $key): self
    {
        return $this->sortBy($key)->reverse();
    }

    public function reverse(): self
    {
        return new static(array_reverse($this->items));
    }

    // ─── 截取 ───

    public function take(int $limit): self
    {
        return new static(array_slice($this->items, 0, $limit));
    }

    public function skip(int $count): self
    {
        return new static(array_slice($this->items, $count));
    }

    public function first(?callable $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            return empty($this->items) ? (is_callable($default) ? $default() : $default) : reset($this->items);
        }
        foreach ($this->items as $key => $value) {
            if ($callback($value, $key)) {
                return $value;
            }
        }
        return is_callable($default) ? $default() : $default;
    }

    public function last(?callable $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            return empty($this->items) ? (is_callable($default) ? $default() : $default) : end($this->items);
        }
        // Iterate in reverse to preserve original keys
        for (end($this->items); ($key = key($this->items)) !== null; prev($this->items)) {
            if ($callback(current($this->items), $key)) {
                return current($this->items);
            }
        }
        return is_callable($default) ? $default() : $default;
    }

    // ─── 分组 ───

    public function groupBy(string $key): self
    {
        $results = [];
        foreach ($this->items as $item) {
            if (is_array($item) && array_key_exists($key, $item)) {
                $groupKey = $item[$key];
            } else {
                $groupKey = null;
            }
            $results[$groupKey][] = $item;
        }
        return new static($results);
    }

    public function keyBy(string $key): self
    {
        $results = [];
        foreach ($this->items as $item) {
            $results[$item[$key] ?? '__keyby_key_' . count($results)] = $item;
        }
        return new static($results);
    }

    // ─── 其他 ───

    public function contains(mixed $value): bool
    {
        return in_array($value, $this->items, true);
    }

    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    public function isNotEmpty(): bool
    {
        return !$this->isEmpty();
    }

    public function values(): self
    {
        return new static(array_values($this->items));
    }

    public function keys(): self
    {
        return new static(array_keys($this->items));
    }

    public function unique(?string $key = null): self
    {
        if ($key === null) {
            return new static(array_unique($this->items));
        }
        $seen = [];
        return $this->filter(function ($item) use ($key, &$seen) {
            $val = $item[$key] ?? null;
            if (in_array($val, $seen, true)) return false;
            $seen[] = $val;
            return true;
        });
    }

    public function reduce(callable $callback, mixed $initial = null): mixed
    {
        return array_reduce($this->items, $callback, $initial);
    }

    public function each(callable $callback): self
    {
        foreach ($this->items as $key => $item) {
            if ($callback($item, $key) === false) break;
        }
        return $this;
    }

    public function tap(callable $callback): self
    {
        $callback($this);
        return $this;
    }

    public function pipe(callable $callback): mixed
    {
        return $callback($this);
    }

    public function flatMap(callable $callback): self
    {
        return $this->map($callback)->flatten();
    }

    public function flatten(): self
    {
        $result = [];
        foreach ($this->items as $item) {
            if (is_array($item)) {
                array_push($result, ...(new static($item))->flatten()->all());
            } else {
                $result[] = $item;
            }
        }
        return new static($result);
    }

    public function chunk(int $size): self
    {
        $chunks = [];
        $chunk = [];
        foreach ($this->items as $key => $item) {
            $chunk[$key] = $item;
            if (count($chunk) === $size) {
                $chunks[] = new static($chunk);
                $chunk = [];
            }
        }
        if (!empty($chunk)) {
            $chunks[] = new static($chunk);
        }
        return new static($chunks);
    }

    public function diff(array $items): self
    {
        $result = [];
        foreach ($this->items as $key => $value) {
            if (!in_array($value, $items, true)) {
                $result[$key] = $value;
            }
        }
        return new static($result);
    }

    public function intersect(array $items): self
    {
        $result = [];
        foreach ($this->items as $key => $value) {
            if (in_array($value, $items, true)) {
                $result[$key] = $value;
            }
        }
        return new static($result);
    }

    public function implode(string $glue = ''): string
    {
        return implode($glue, array_map(fn($item) => (string) $item, $this->items));
    }

    public function flip(): self
    {
        $result = [];
        foreach ($this->items as $key => $value) {
            if (is_string($value) || is_int($value)) {
                $result[$value] = $key;
            }
        }
        return new static($result);
    }

    public function zip(array $items): self
    {
        $result = [];
        $count = max(count($this->items), count($items));
        for ($i = 0; $i < $count; $i++) {
            $result[] = [$this->items[$i] ?? null, $items[$i] ?? null];
        }
        return new static($result);
    }

    public function nth(int $nth, int $offset = 0): self
    {
        if ($nth < 1) {
            throw new \InvalidArgumentException('nth must be at least 1');
        }
        $result = [];
        $position = 0;
        foreach ($this->items as $key => $item) {
            if ($position >= $offset && ($position - $offset) % $nth === 0) {
                $result[$key] = $item;
            }
            $position++;
        }
        return new static($result);
    }

    public function forPage(int $page, int $perPage): self
    {
        $offset = ($page - 1) * $perPage;
        return $this->slice($offset, $perPage);
    }

    public function slice(int $offset, ?int $length = null): self
    {
        return new static(array_slice($this->items, $offset, $length));
    }

    public function split(int $number): self
    {
        $chunks = array_chunk($this->items, (int) ceil(count($this->items) / max(1, $number)));
        return new static(array_map(fn($chunk) => new static($chunk), $chunks));
    }

    public function collapse(): self
    {
        $result = [];
        foreach ($this->items as $item) {
            if (is_array($item)) {
                array_push($result, ...$item);
            }
        }
        return new static($result);
    }

    public function merge(array $items): self
    {
        return new static(array_merge($this->items, $items));
    }

    public function pull(mixed $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->items)) {
            $value = $this->items[$key];
            unset($this->items[$key]);
            return $value;
        }
        return $default;
    }

    public function forget(mixed $key): self
    {
        unset($this->items[$key]);
        return $this;
    }

    public function toArray(): array
    {
        return array_map(fn($item) => $item instanceof \JsonSerializable ? $item->jsonSerialize() : $item, $this->items);
    }

    public function toJson(int $options = JSON_UNESCAPED_UNICODE): string
    {
        return json_encode($this->toArray(), $options) ?: '[]';
    }

    // ─── 接口实现 ───

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->items);
    }

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->items);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->items[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->items[$offset]);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function __toString(): string
    {
        return $this->toJson();
    }
}