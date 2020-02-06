<?php

namespace Conduit\Endpoints;

use Conduit\Transformers\ErrorResponse;
use Exception;
use Countable;
use ArrayAccess;
use IteratorAggregate;
use Conduit\Adapters\Adapter;
use InvalidArgumentException;
use Conduit\Transformers\RawResponse;
use Psr\Http\Message\ResponseInterface;
use Conduit\Transformers\ResponseStruct;

/**
 * Class Endpoint
 * @package Conduit\Endpoints
 * @mixin Adapter
 */
class Endpoint implements ArrayAccess, Countable, IteratorAggregate
{
    const CONTENT_TYPE_JSON = 'application/json';
    const CONTENT_TYPE_URL_ENCODED_FORM = 'application/x-www-form-urlencoded';
    const CONTENT_TYPE_MULTIPART_FORM = 'multipart/form-data';
    const CONTENT_TYPE_XML = 'application/xml';
    const CONTENT_TYPE_HTML = 'text/html';

    /**
     * @var Adapter
     */
    protected $adapter;

    /**
     * @var ResponseInterface
     */
    protected $rawResponse = null;

    /**
     * @var ResponseStruct
     */
    protected $responseContent = null;

    /**
     * @var string|null
     */
    protected $serviceName = null;

    /**
     * @var string
     */
    protected $protocol;

    /**
     * @var string
     */
    protected $domain;

    /**
     * @var string
     */
    protected $route = '/';

    /**
     * @var string
     */
    protected $method = 'GET';

    /**
     * @var array
     */
    protected $query = [];

    /**
     * @var array
     */
    protected $body = [];

    /**
     * @var array
     */
    protected $headers = [
        'content-type' => self::CONTENT_TYPE_JSON,
        'accept' => self::CONTENT_TYPE_JSON
    ];

    /**
     * @var array
     */
    protected $cookies = [];

    /**
     * @var string
     */
    protected $transformerName;

    /**
     * @var ResponseStruct
     */
    protected $transformer;

    /**
     * @var string
     */
    protected $errorTransformerName;

    /**
     * @var ErrorResponse
     */
    protected $errorTransformer;

    /**
     * @var string[]|callable[]
     */
    protected $middleware = [];

    /**
     * @var bool
     */
    protected $strictMode;

    /**
     * Endpoint constructor.
     */
    public function __construct()
    {
        $this->strictMode = !is_numeric($this->strictMode) ? $this->strictMode : config('conduit.strict-mode', false);

        $serviceName = $this->serviceName ?: config('conduit.default_service');
        $config = config("conduit.services.$serviceName");
        $this->setMiddleware($this->middleware);

        $this->protocol = ($this->protocol ?: $config['protocol']) ?: 'https';
        $this->domain = $this->domain ?: $config['domain'];

        $adapter = $this->newAdapterInstance();
        $this->setAdapter($adapter);

        $this->setTransformer();
    }

    /**
     * @param array $middleware
     * @return $this
     */
    public function setMiddleware(array $middleware)
    {
        $this->middleware = [];
        foreach ($middleware as $name => $middlewareItem) {
            if (is_string($middlewareItem)) {
                $name = $middlewareItem;
                $middlewareItem = app($middlewareItem);
            }

            if (!is_callable($middlewareItem)) {
                throw new InvalidArgumentException('Middleware must be callable, or the name of a callable class.'
                    ."'".get_class($middlewareItem)."' given.");
            }

            $this->middleware[$name] = $middlewareItem;
        }

        return $this;
    }

    /**
     * @return callable[]
     */
    public function getMiddleware()
    {
        return $this->middleware;
    }

    /**
     * @param string|null $bridgeName
     * @return Adapter
     */
    public function newAdapterInstance($bridgeName = null)
    {
        $adapter = app(Adapter::class, compact('bridgeName'))
            ->setMethod($this->method)
            ->setProtocol($this->protocol)
            ->setDomain($this->domain)
            ->setRoute($this->route)
            ->setQuery($this->query)
            ->setBody($this->body)
            ->setHeaders($this->headers)
            ->setCookies($this->cookies);

        foreach ($this->middleware as $middleware) {
            $adapter->pushHandler($middleware);
        }

        return $adapter;
    }

    /**
     * @param Adapter $adapter
     * @return $this
     */
    public function setAdapter(Adapter $adapter)
    {
        $this->adapter = $adapter;

        return $this;
    }

    /**
     * @return $this
     * @throws Exception
     */
    public function send()
    {
        $transformer = $this->getTransformer();
        $this->rawResponse = $this->adapter->send();

        try {
            $this->responseContent = $transformer($this->rawResponse);
        } catch (Exception $error) {
            if ($this->strictMode) {
                throw $error;
            }

            $errorTransformer = $this->getErrorTransformer()->setError($error);
            $this->responseContent = $errorTransformer($this->rawResponse);
        }

        return $this;
    }

    /**
     * @param ResponseStruct|null $transformer
     * @return Endpoint
     */
    public function setTransformer(ResponseStruct $transformer = null): Endpoint
    {
        $contentType = $this->headers['accept'] ?? null;
        $defaultTransformer = ResponseStruct::make($contentType);
        $localTransformer = $this->transformerName ?  app($this->transformerName) : $defaultTransformer;
        $this->transformer = $transformer ?: $localTransformer;

        if (!($this->transformer instanceof ResponseStruct)) {
            throw new InvalidArgumentException('Transformer must be an instance of '.ResponseStruct::class);
        }

        return $this;
    }

    /**
     * @return ResponseStruct
     */
    public function getTransformer()
    {
        return $this->transformer;
    }

    /**
     * @param ErrorResponse|null $transformer
     * @return Endpoint
     */
    public function setErrorTransformer(ErrorResponse $transformer = null): Endpoint
    {
        $defaultTransformer = app(RawResponse::class);
        $localTransformer = $this->errorTransformerName ?  app($this->errorTransformerName) : $defaultTransformer;
        $this->errorTransformer = $transformer ?: $localTransformer;

        if (!($this->errorTransformer instanceof ErrorResponse)) {
            throw new InvalidArgumentException('Transformer must be an instance of '.ErrorResponse::class);
        }

        return $this;
    }

    /**
     * @return ErrorResponse
     */
    public function getErrorTransformer()
    {
        return $this->errorTransformer;
    }

    /**
     * @return ResponseInterface|null
     */
    public function getRawResponse(): ?ResponseInterface
    {
        return $this->rawResponse;
    }

    /**
     * @return ResponseStruct
     */
    public function getResponseContent()
    {
        return $this->responseContent;
    }

    /**
     * @param string $method
     * @param array $args
     * @return mixed
     */
    public function __call($method, array $args)
    {
        return $this->adapter->$method(...$args);
    }

    /**
     * {@inheritDoc}
     */
    public function getIterator()
    {
        return $this->responseContent->iterator();
    }

    /**
     * {@inheritDoc}
     */
    public function offsetExists($offset)
    {
        return $this->responseContent->has($offset);
    }

    /**
     * {@inheritDoc}
     */
    public function offsetGet($offset)
    {
        return $this->responseContent->get($offset);
    }

    /**
     * {@inheritDoc}
     */
    public function offsetSet($offset, $value)
    {
        $this->responseContent->set($offset, $value);
    }

    /**
     * {@inheritDoc}
     */
    public function offsetUnset($offset)
    {
        $this->responseContent->unset($offset);
    }

    /**
     * {@inheritDoc}
     */
    public function count()
    {
        return $this->responseContent->count();
    }

    /**
     * @param string|int $name
     * @return bool
     */
    public function __isset($name)
    {
        return $this->offsetExists($name);
    }

    /**
     * @param string|int $name
     * @return mixed
     */
    public function __get($name)
    {
        return $this->offsetGet($name);
    }

    /**
     * @param string|int $name
     * @param mixed $value
     * @return mixed
     */
    public function __set($name, $value)
    {
        return $this->offsetSet($name, $value);
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return (string)$this->responseContent;
    }
}
