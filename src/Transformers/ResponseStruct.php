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
     * @param null $contentType
     * @return ResponseStruct
     */
    public static function make($contentType = null)
    {
        if (array_key_exists($contentType, self::$responseStructs)) {
            return self::$responseStructs[$contentType];
        }

        switch ($contentType) {
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
     * @param ResponseInterface $response
     * @return ResponseInterface
     */
    abstract public function __invoke(ResponseInterface $response);

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
}
