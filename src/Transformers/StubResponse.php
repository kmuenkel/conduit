<?php

namespace Conduit\Transformers;

use ArrayIterator;

/**
 * Trait StubResponse
 * @package Conduit\Transformers
 * @mixin ResponseStruct
 */
trait StubResponse
{
    /**
     * @param string|int $offset
     * @return bool
     */
    public function has($offset): bool
    {
        return false;
    }

    /**
     * @param string|int $offset
     * @return $this
     */
    public function unset($offset)
    {
        return $this;
    }

    /**
     * @param string|int $offset
     * @return mixed|null
     */
    public function get($offset)
    {
        return null;
    }

    /**
     * @param string|int $offset
     * @param mixed $value
     * @return $this
     */
    public function set($offset, $value)
    {
        return $this;
    }

    /**
     * @return int
     */
    public function count(): int
    {
        return 0;
    }

    /**
     * @return ArrayIterator
     */
    public function iterator(): ArrayIterator
    {
        return app(ArrayIterator::class, ['array' => []]);
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        return (string)$this->content;
    }
}
