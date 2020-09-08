<?php

namespace Conduit\Transformers;

use ArrayIterator;
use Conduit\Endpoints\Endpoint;
use Psr\Http\Message\ResponseInterface;

/**
 * Class TransformerFactory
 * @package Conduit\Transformers
 */
abstract class ResponseStruct
{
    /**
     * @var array
     */
    protected static $responseStructs = [];

    /**
     * @var mixed|null
     */
    protected $content = null;

    /**
     * @var int
     */
    protected static $iteratorFlags = 0;

    /**
     * @param null $contentType
     * @return ResponseStruct
     */
    public static function make($contentType = null)
    {
        $baseContentType = current(explode(';', (string)$contentType));
        if ($contentType && array_key_exists($contentType, self::$responseStructs)) {
            return self::$responseStructs[$contentType];
        } elseif ($baseContentType && array_key_exists($baseContentType, self::$responseStructs)) {
            return self::$responseStructs[$baseContentType];
        }

        switch ($baseContentType) {
            case Endpoint::CONTENT_TYPE_JSON:
                return app(JsonResponse::class);

                break;
            case Endpoint::CONTENT_TYPE_XML:
                return app(XmlResponse::class);

                break;
            case Endpoint::CONTENT_TYPE_HTML:
                return app(HtmlResponse::class);

                break;
            default:
                return app(RawResponse::class);
        }
    }

    /**
     * @param $flags
     */
    public static function setIteratorFlags($flags)
    {
        static::$iteratorFlags = $flags;
    }

    /**
     * Allow for adding support for custom response type handlers when the App boots
     *
     * @param string|string[] $contentTypes
     * @param ResponseStruct $struct
     */
    public static function addStruct($contentTypes, self $struct)
    {
        $contentTypes = (array)$contentTypes;

        foreach ($contentTypes as $contentType) {
            self::$responseStructs[$contentType] = $struct;
        }
    }

    /**
     * @return mixed|null
     */
    public function getRaw()
    {
        return $this->content;
    }

    /**
     * @param ResponseInterface $response
     * @return ResponseStruct
     */
    abstract public function __invoke(ResponseInterface $response): ResponseStruct;

    /**
     * @param string|int $offset
     * @return bool
     */
    abstract public function has($offset): bool;

    /**
     * @param string|int $offset
     * @return $this
     */
    abstract public function unset($offset);

    /**
     * @param string|int $offset
     * @return mixed|null
     */
    abstract public function get($offset);

    /**
     * @param string|int $offset
     * @param mixed $value
     * @return $this
     */
    abstract public function set($offset, $value);

    /**
     * @return int
     */
    abstract public function count(): int;

    /**
     * @return ArrayIterator
     */
    abstract public function iterator(): ArrayIterator;

    /**
     * @return string
     */
    abstract public function __toString(): string;

    /**
     * @return array
     */
    public function all()
    {
        return iterator_to_array($this->iterator());
    }
}
