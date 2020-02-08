<?php

namespace Conduit\Endpoints;

use Exception;
use Countable;
use ArrayAccess;
use IteratorAggregate;
use Conduit\Adapters\Adapter;
use InvalidArgumentException;
use Conduit\Transformers\ErrorResponse;
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
    protected $headers = [];

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
     * Endpoint constructor.
     */
    public function __construct()
    {
        $serviceName = $this->serviceName ?: config('conduit.default_service');
        $config = config("conduit.services.$serviceName");
        $this->setMiddleware($this->middleware);

        $this->protocol = ($this->protocol ?: $config['protocol']) ?: 'http';
        $this->domain = $this->domain ?: $config['domain'];

        $adapter = $this->newAdapterInstance();
        $this->setAdapter($adapter);

        $this->setTransformer();
        $this->setErrorTransformer();
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
                $name = is_int($name) ? $middlewareItem : $name;
                $middlewareItem = app($middlewareItem);
            }

            $this->addMiddleware($middlewareItem, $name);
        }

        return $this;
    }

    /**
     * @param callable $middleware
     * @param string|null $name
     * @return $this
     */
    public function addMiddleware(callable $middleware, $name = null)
    {
        $name = $name ?: count($this->middleware);
        $this->middleware[$name] = $middleware;
        $this->adapter->pushHandler($middleware);

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
            ->setMethod($this->getMethod())
            ->setProtocol($this->getProtocol())
            ->setDomain($this->getDomain())
            ->setRoute($this->getRoute())
            ->setQuery($this->getQuery())
            ->setBody($this->getBody())
            ->setHeaders($this->getHeaders())
            ->setCookies($this->getCookies());

        foreach ($this->getMiddleware() as $middleware) {
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
     */
    public function send()
    {
        $this->rawResponse = $this->adapter->send();
        $transformer = $this->getTransformer();

        try {
            $this->responseContent = $transformer($this->rawResponse);
        } catch (Exception $error) {
            $defaultTransformer = app(ErrorResponse::class)->setError($error);
            $errorTransformer = $this->getErrorTransformer();
            $errorTransformer = $errorTransformer ? $errorTransformer->setError($error) : null;
            $inferredTransformer = $this->guessTransformer($defaultTransformer);
            $newTransformer = $errorTransformer ?: $inferredTransformer;
            $newTransformer = ($newTransformer instanceof $transformer) ? $defaultTransformer : $newTransformer;

            $this->responseContent = $newTransformer($this->rawResponse);
        }

        return $this;
    }

    /**
     * @param ResponseStruct|null $default
     * @return ResponseStruct
     */
    protected function guessTransformer(ResponseStruct $default = null)
    {
        $requestContentType = $this->headers['accept'] ?? null;
        $responseContentType = !$this->rawResponse ? null :
            (current($this->rawResponse->getHeader('content-type')) ?: null);
        $contentType = $responseContentType ?: $requestContentType;
        $transformer = (!$contentType && $default) ? $default : ResponseStruct::make($contentType);

        return $transformer;
    }

    /**
     * @param ResponseStruct|null $transformer
     * @return Endpoint
     */
    public function setTransformer(ResponseStruct $transformer = null): Endpoint
    {
        $localTransformer = $this->transformerName ?  app($this->transformerName) : null;
        $this->transformer = $transformer ?: $localTransformer;

        if ($this->transformer && !($this->transformer instanceof ResponseStruct)) {
            throw new InvalidArgumentException('Transformer must be an instance of '.ResponseStruct::class
                .". '".get_class($this->transformer)."' given.");
        }

        return $this;
    }

    /**
     * @return ResponseStruct
     */
    public function getTransformer()
    {
        return $this->transformer ?: $this->guessTransformer();
    }

    /**
     * @param ErrorResponse|null $transformer
     * @return Endpoint
     */
    public function setErrorTransformer(ErrorResponse $transformer = null): Endpoint
    {
        $localTransformer = $this->errorTransformerName ?  app($this->errorTransformerName) : null;
        $this->errorTransformer = $transformer ?: $localTransformer;

        if ($this->errorTransformer && !($this->errorTransformer instanceof ErrorResponse)) {
            throw new InvalidArgumentException('Transformer must be an instance of '.ErrorResponse::class
                .". '".get_class($this->errorTransformer)."' given.");
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
     * @return string
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * @return string
     */
    public function getProtocol(): string
    {
        return $this->protocol;
    }

    /**
     * @return string
     */
    public function getDomain(): string
    {
        return $this->domain;
    }

    /**
     * @return string
     */
    public function getRoute(): string
    {
        return $this->route;
    }

    /**
     * @return array
     */
    public function getQuery(): array
    {
        return $this->query;
    }

    /**
     * @return array
     */
    public function getBody(): array
    {
        return $this->body;
    }

    /**
     * @return array
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * @return array
     */
    public function getCookies(): array
    {
        return $this->cookies;
    }

    /**
     * @return Adapter
     */
    public function getAdapter(): Adapter
    {
        return $this->adapter;
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