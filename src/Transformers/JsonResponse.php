<?php

namespace Conduit\Transformers;

use StdClass;
use JsonException;
use ArrayIterator;
use Psr\Http\Message\ResponseInterface;

/**
 * Class JsonTransformer
 * @package Conduit\Transformers
 */
class JsonResponse extends ResponseStruct
{
    /**
     * @var object
     */
    protected $content = (object)[];

    /**
     * @param ResponseInterface $response
     * @return StdClass|array
     * @throws JsonException
     */
    public function __invoke(ResponseInterface $response)
    {
        $body = $response->getBody();
        $json = json_decode($body);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new JsonException(json_last_error_msg());
        }

        return $this->content = $json;
    }

    /**
     * {@inheritDoc}
     */
    public function has($offset): bool
    {
        return property_exists($offset, $this->content);
    }

    /**
     * {@inheritDoc}
     */
    public function unset($offset)
    {
        unset($this->content->$offset);

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function get($offset)
    {
        return $this->has($offset) ? $this->content->$offset : null;
    }

    /**
     * {@inheritDoc}
     */
    public function set($offset, $value)
    {
        $this->content->$offset = $value;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function count(): int
    {
        return count((array)$this->content);
    }

    /**
     * {@inheritDoc}
     */
    public function iterator(): ArrayIterator
    {
        return app(ArrayIterator::class, ['array' => (array)$this->content]);
    }

    /**
     * @inheritDoc
     */
    public function __toString(): string
    {
        return json_encode($this->content);
    }
}
