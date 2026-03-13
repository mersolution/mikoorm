<?php
/**
 * MIT License
 * Copyright (c) 2026 Mersolution Technology Ltd.
 * 
 * JsonColumn - JSON column support for models
 * Similar to mersolutionCore JsonColumn.cs
 */

namespace Miko\Database\ORM;

/**
 * JSON Column trait for models with JSON columns
 * 
 * Usage:
 * class User extends Model {
 *     use JsonColumnTrait;
 *     
 *     protected array $jsonColumns = ['settings', 'metadata'];
 * }
 * 
 * $user->setJson('settings', ['theme' => 'dark']);
 * $theme = $user->getJson('settings', 'theme');
 * $user->appendJson('settings', 'notifications', true);
 */
trait JsonColumnTrait
{
    /**
     * Get JSON column value
     * 
     * @param string $column Column name
     * @param string|null $key Dot notation key (optional)
     * @param mixed $default Default value
     * @return mixed
     */
    public function getJson(string $column, ?string $key = null, mixed $default = null): mixed
    {
        $value = $this->getAttribute($column);
        
        if ($value === null) {
            return $default;
        }

        // Decode if string
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        if (!is_array($value)) {
            return $default;
        }

        // Return full array if no key specified
        if ($key === null) {
            return $value;
        }

        // Support dot notation
        return $this->getNestedValue($value, $key, $default);
    }

    /**
     * Set JSON column value
     * 
     * @param string $column Column name
     * @param array|string $keyOrValue Key (with value) or full array
     * @param mixed $value Value (if key provided)
     * @return static
     */
    public function setJson(string $column, array|string $keyOrValue, mixed $value = null): static
    {
        // If array provided, set entire column
        if (is_array($keyOrValue)) {
            $this->setAttribute($column, json_encode($keyOrValue, JSON_UNESCAPED_UNICODE));
            return $this;
        }

        // Get existing value
        $existing = $this->getJson($column) ?? [];
        
        // Set nested value
        $this->setNestedValue($existing, $keyOrValue, $value);
        
        $this->setAttribute($column, json_encode($existing, JSON_UNESCAPED_UNICODE));
        return $this;
    }

    /**
     * Append value to JSON array
     * 
     * @param string $column Column name
     * @param string $key Dot notation key
     * @param mixed $value Value to append
     * @return static
     */
    public function appendJson(string $column, string $key, mixed $value): static
    {
        $existing = $this->getJson($column, $key) ?? [];
        
        if (!is_array($existing)) {
            $existing = [$existing];
        }
        
        $existing[] = $value;
        
        return $this->setJson($column, $key, $existing);
    }

    /**
     * Remove key from JSON column
     * 
     * @param string $column Column name
     * @param string $key Dot notation key
     * @return static
     */
    public function removeJson(string $column, string $key): static
    {
        $existing = $this->getJson($column) ?? [];
        
        $this->removeNestedValue($existing, $key);
        
        $this->setAttribute($column, json_encode($existing, JSON_UNESCAPED_UNICODE));
        return $this;
    }

    /**
     * Check if JSON key exists
     * 
     * @param string $column Column name
     * @param string $key Dot notation key
     * @return bool
     */
    public function hasJson(string $column, string $key): bool
    {
        $value = $this->getJson($column, $key, '__NOT_FOUND__');
        return $value !== '__NOT_FOUND__';
    }

    /**
     * Increment JSON numeric value
     */
    public function incrementJson(string $column, string $key, int|float $amount = 1): static
    {
        $current = $this->getJson($column, $key, 0);
        return $this->setJson($column, $key, $current + $amount);
    }

    /**
     * Decrement JSON numeric value
     */
    public function decrementJson(string $column, string $key, int|float $amount = 1): static
    {
        return $this->incrementJson($column, $key, -$amount);
    }

    /**
     * Get nested value using dot notation
     */
    private function getNestedValue(array $array, string $key, mixed $default = null): mixed
    {
        $keys = explode('.', $key);
        $value = $array;

        foreach ($keys as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Set nested value using dot notation
     */
    private function setNestedValue(array &$array, string $key, mixed $value): void
    {
        $keys = explode('.', $key);
        $current = &$array;

        foreach ($keys as $i => $segment) {
            if ($i === count($keys) - 1) {
                $current[$segment] = $value;
            } else {
                if (!isset($current[$segment]) || !is_array($current[$segment])) {
                    $current[$segment] = [];
                }
                $current = &$current[$segment];
            }
        }
    }

    /**
     * Remove nested value using dot notation
     */
    private function removeNestedValue(array &$array, string $key): void
    {
        $keys = explode('.', $key);
        $current = &$array;

        foreach ($keys as $i => $segment) {
            if ($i === count($keys) - 1) {
                unset($current[$segment]);
            } else {
                if (!isset($current[$segment]) || !is_array($current[$segment])) {
                    return;
                }
                $current = &$current[$segment];
            }
        }
    }
}

/**
 * JSON Column helper class for static operations
 */
class JsonColumn
{
    /**
     * Encode value to JSON
     */
    public static function encode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Decode JSON to array
     */
    public static function decode(string $json): ?array
    {
        $result = json_decode($json, true);
        return is_array($result) ? $result : null;
    }

    /**
     * Merge JSON values
     */
    public static function merge(string $json1, string $json2): string
    {
        $arr1 = self::decode($json1) ?? [];
        $arr2 = self::decode($json2) ?? [];
        
        return self::encode(array_merge_recursive($arr1, $arr2));
    }

    /**
     * Get value from JSON string using dot notation
     */
    public static function get(string $json, string $key, mixed $default = null): mixed
    {
        $array = self::decode($json);
        
        if ($array === null) {
            return $default;
        }

        $keys = explode('.', $key);
        $value = $array;

        foreach ($keys as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Set value in JSON string using dot notation
     */
    public static function set(string $json, string $key, mixed $value): string
    {
        $array = self::decode($json) ?? [];
        
        $keys = explode('.', $key);
        $current = &$array;

        foreach ($keys as $i => $segment) {
            if ($i === count($keys) - 1) {
                $current[$segment] = $value;
            } else {
                if (!isset($current[$segment]) || !is_array($current[$segment])) {
                    $current[$segment] = [];
                }
                $current = &$current[$segment];
            }
        }

        return self::encode($array);
    }
}

/**
 * JSON Dictionary - Key-value store as JSON
 * Similar to mersolutionCore JsonDictionary
 */
class JsonDictionary implements \ArrayAccess, \Countable, \IteratorAggregate
{
    private array $data = [];

    public function __construct(array|string $data = [])
    {
        if (is_string($data)) {
            $this->data = json_decode($data, true) ?? [];
        } else {
            $this->data = $data;
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): self
    {
        $this->data[$key] = $value;
        return $this;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function remove(string $key): self
    {
        unset($this->data[$key]);
        return $this;
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public function toJson(): string
    {
        return json_encode($this->data, JSON_UNESCAPED_UNICODE);
    }

    public function __get(string $key): mixed
    {
        return $this->get($key);
    }

    public function __set(string $key, mixed $value): void
    {
        $this->set($key, $value);
    }

    public function __isset(string $key): bool
    {
        return $this->has($key);
    }

    public function __unset(string $key): void
    {
        $this->remove($key);
    }

    // ArrayAccess
    public function offsetExists(mixed $offset): bool
    {
        return $this->has($offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->get($offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->set($offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->remove($offset);
    }

    // Countable
    public function count(): int
    {
        return count($this->data);
    }

    // IteratorAggregate
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->data);
    }
}

/**
 * JSON List - Array store as JSON
 * Similar to mersolutionCore JsonList
 */
class JsonList implements \ArrayAccess, \Countable, \IteratorAggregate
{
    private array $items = [];

    public function __construct(array|string $items = [])
    {
        if (is_string($items)) {
            $this->items = json_decode($items, true) ?? [];
        } else {
            $this->items = array_values($items);
        }
    }

    public function add(mixed $item): self
    {
        $this->items[] = $item;
        return $this;
    }

    public function addRange(array $items): self
    {
        foreach ($items as $item) {
            $this->items[] = $item;
        }
        return $this;
    }

    public function get(int $index): mixed
    {
        return $this->items[$index] ?? null;
    }

    public function set(int $index, mixed $value): self
    {
        $this->items[$index] = $value;
        return $this;
    }

    public function remove(mixed $item): self
    {
        $key = array_search($item, $this->items, true);
        if ($key !== false) {
            array_splice($this->items, $key, 1);
        }
        return $this;
    }

    public function removeAt(int $index): self
    {
        if (isset($this->items[$index])) {
            array_splice($this->items, $index, 1);
        }
        return $this;
    }

    public function contains(mixed $item): bool
    {
        return in_array($item, $this->items, true);
    }

    public function indexOf(mixed $item): int
    {
        $index = array_search($item, $this->items, true);
        return $index !== false ? $index : -1;
    }

    public function clear(): self
    {
        $this->items = [];
        return $this;
    }

    public function first(): mixed
    {
        return $this->items[0] ?? null;
    }

    public function last(): mixed
    {
        return !empty($this->items) ? $this->items[count($this->items) - 1] : null;
    }

    public function toArray(): array
    {
        return $this->items;
    }

    public function toJson(): string
    {
        return json_encode($this->items, JSON_UNESCAPED_UNICODE);
    }

    // ArrayAccess
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->items[$offset]);
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
        $this->removeAt($offset);
    }

    // Countable
    public function count(): int
    {
        return count($this->items);
    }

    // IteratorAggregate
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }
}
