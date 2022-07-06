<?php

namespace Conduit\Endpoints;

use Exception;
use Countable;
use ArrayAccess;
use IteratorAggregate;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Http\Response;
use Conduit\Adapters\Adapter;
use InvalidArgumentException;
use Psr\Http\Message\UriInterface;
use Conduit\Transformers\JsonResponse;
use Conduit\Transformers\ErrorResponse;
use Psr\Http\Message\ResponseInterface;
use Conduit\Transformers\ResponseStruct;
use function GuzzleHttp\Psr7\stream_for;
use Conduit\Exceptions\EndpointException;
use GuzzleHttp\Psr7\Response as GuzzleResponse;

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
     * @var Adapter|null
     */
    protected ?Adapter $adapter = null;

    /**
     * @var ResponseInterface|null
     */
    protected ?ResponseInterface $rawResponse = null;

    /**
     * @var ResponseStruct|null
     */
    protected ?ResponseStruct $responseContent = null;

    /**
     * @var string
     */
    protected string $serviceName = '';

    /**
     * @var string
     */
    protected string $protocol = '';

    /**
     * @var string
     */
    protected string $domain = '';

    /**
     * @var string
     */
    protected string $route = '/';

    /**
     * @var string
     */
    protected string $method = 'GET';

    /**
     * @var array
     */
    protected array $query = [];

    /**
     * @var array
     */
    protected array $body = [];

    /**
     * @var array
     */
    protected array $headers = [];

    /**
     * @var array
     */
    protected array $cookies = [];

    /**
     * @var array
     */
    protected array $params = [];

    /**
     * @var string
     */
    protected string $transformerName = '';

    /**
     * @var ResponseStruct|null
     */
    protected ?ResponseStruct $transformer = null;

    /**
     * @var string
     */
    protected string $errorTransformerName = '';

    /**
     * @var ErrorResponse|null
     */
    protected ?ErrorResponse $errorTransformer = null;

    /**
     * @var string[]|callable[]
     */
    protected array $middleware = [];

    /**
     * @var EndpointException|null
     */
    protected ?EndpointException $error = null;

    /**
     * @param string $bridgeName
     * @param array $config
     */
    public function __construct(string $bridgeName = '', array $config = [])
    {
        $bridgeName = $bridgeName ?: config('conduit.default_bridge');
        $config = $config ?: config('conduit.services.'.($this->serviceName ?: config('conduit.default_service')));
        $localPartsSet = $this->protocol || $this->domain;
        $configPartsSet = isset($config['protocol']) || isset($config['domain']);
        $configUrl = $config['url'] ?? null;

        if (!$localPartsSet && !$configPartsSet && $configUrl) {
            $configUrl = parse_url($configUrl);
            $this->protocol = $configUrl['scheme'] ?? null;
            $this->domain = $configUrl['host'] ?? null;
        }

        $this->protocol = ($this->protocol ?: $config['protocol']) ?: 'http';
        $this->domain = $this->domain ?: $config['domain'];

        $adapter = $this->adapter ?: $this->newAdapterInstance($bridgeName, $config);
        $this->setAdapter($adapter);

        $this->setTransformer();
        $this->setErrorTransformer();
    }

    /**
     * @param array $params
     * @return $this
     */
    public function setParams(array $params): self
    {
        $this->params = $params;

        return $this;
    }

    /**
     * @return array
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * @param string $name
     * @param $param
     * @return $this
     */
    public function setParam(string $name, $param): self
    {
        $this->params[$name] = $param;

        return $this;
    }

    /**
     * @param string|UriInterface $uri
     * @return $this
     */
    public function setUrl($uri): self
    {
        $url = $uri instanceof Uri ? $uri : app(Uri::class, compact('uri'));
        $url->getScheme() && $this->setProtocol($url->getScheme());
        $url->getHost() && $this->setDomain($url->getHost());
        $this->setRoute(ltrim($url->getPath(), '/'));
        $query = $url->getQuery();
        parse_str(urldecode($query), $query);
        /** @var array $query */
        $this->setQuery($query);

        return $this;
    }

    /**
     * @return UriInterface
     */
    public function getUrl(): UriInterface
    {
        return Uri::fromParts([
            'scheme' => $this->protocol,
            'host' => $this->domain,
            'path' => $this->route,
            'query' => http_build_query($this->query)
        ]);
    }

    /**
     * @param array $middleware
     * @return $this
     */
    public function setMiddleware(array $middleware): self
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
    public function addMiddleware(callable $middleware, string $name = null): self
    {
        $name = $name ?: count($this->middleware);
        $this->middleware[$name] = $middleware;
        $this->adapter->pushHandler($middleware);

        return $this;
    }

    /**
     * @return callable[]
     */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    /**
     * @param string|null $bridgeName
     * @param array $config
     * @return Adapter
     */
    public function newAdapterInstance(string $bridgeName = null, array $config = []): Adapter
    {
        $defaultHandlers = $this->adapter ? $this->adapter->getHandlers() : [];

        /** @var Adapter $adapter */
        $adapter = ($this->adapter ? clone $this->adapter : app(Adapter::class, compact('bridgeName', 'config')))
            ->setMethod($this->getMethod())
            ->setRoute($this->getRoute())
            ->setQuery($this->getQuery())
            ->setBody($this->getBody())
            ->setHeaders($this->getHeaders())
            ->setCookies($this->getCookies());

        $this->getProtocol() && $adapter->setProtocol($this->getProtocol());
        $this->getDomain() && $adapter->setDomain($this->getDomain());
        $adapter->setHandlers(array_merge($defaultHandlers, $this->getMiddleware()));

        return $adapter;
    }

    /**
     * @param Adapter $adapter
     * @return $this
     */
    public function setAdapter(Adapter $adapter): self
    {
        $this->adapter = $adapter;

        return $this;
    }

    /**
     * @param ResponseInterface $rawResponse
     * @return $this
     */
    public function setRawResponse(ResponseInterface $rawResponse): self
    {
        $this->rawResponse = $rawResponse;
        $this->transformContent();

        return $this;
    }

    /**
     * @param string $body
     * @param int $status
     * @param array $headers
     * @return $this
     */
    public function makeResponse(string $body = '', $status = Response::HTTP_OK, array $headers = []): self
    {
        $body = stream_for($body);
        $response = app(GuzzleResponse::class, compact('status', 'headers', 'body'));
        $this->setRawResponse($response);

        return $this;
    }

    /**
     * @void
     */
    protected function transformContent()
    {
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

        $responseContent = $this->getResponseContent();
        $arrayIsNumeric = fn (array $array): bool => ($keys = array_keys($array)) === array_keys($keys);

        if (!($responseContent instanceof JsonResponse) || !$arrayIsNumeric($content = $responseContent->all())) {
            return;
        }

        $response = $this->getAdapter()->getResponse();

        foreach ($content as $index => $result) {
            $response = new GuzzleResponse($response->getStatusCode(), $response->getHeaders(), json_encode($result));
            $nested = (clone $this)->setRawResponse($response);
            $responseContent->set($index, $nested);
        }

        $this->responseContent = $responseContent;
    }

    /**
     * @return $this
     */
    public function send(): self
    {
        foreach ($this->params as $name => $param) {
            $param = is_bool($param) ? (int)$param : $param;
            $this->route = preg_replace('/{'.preg_quote($name).'}/', (string)$param, $this->route);
        }

        $this->route = preg_replace('/{.+?}/', '', $this->route);
        $adapter = $this->newAdapterInstance();
        $this->setAdapter($adapter);

        $this->rawResponse = $this->adapter->send();
        $this->transformContent();

        if ($error = $this->adapter->getBridge()->getError()) {
            $this->error = (new EndpointException($error->getMessage(), $error->getCode(), $error))
                ->setContent($this->responseContent)
                ->setRaw($this->rawResponse);
        }

        return $this;
    }

    /**
     * @return EndpointException|null
     */
    public function getError(): ?EndpointException
    {
        return $this->error;
    }

    /**
     * @param ResponseStruct|null $default
     * @return ResponseStruct
     */
    protected function guessTransformer(ResponseStruct $default = null): ResponseStruct
    {
        $requestContentType = $this->headers['accept'] ?? null;
        $responseContentType = !$this->rawResponse ? null :
            (current($this->rawResponse->getHeader('content-type')) ?: null);
        $contentType = $responseContentType ?: $requestContentType;

        return (!$contentType && $default) ? $default : ResponseStruct::make($contentType);
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
    public function getTransformer(): ResponseStruct
    {
        return $this->transformer ?: $this->guessTransformer();
    }

    /**
     * @param ErrorResponse|null $transformer
     * @return $this
     */
    public function setErrorTransformer(ErrorResponse $transformer = null): self
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
    public function getErrorTransformer(): ErrorResponse
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
     * @return ResponseStruct|null
     */
    public function getResponseContent(): ?ResponseStruct
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
     * @return Adapter|null
     */
    public function getAdapter(): ?Adapter
    {
        return $this->adapter;
    }

    /**
     * @param array $headers
     * @return $this
     */
    public function setHeaders(array $headers): self
    {
        $this->headers = $headers;
        $this->adapter->setHeaders($headers);

        return $this;
    }

    /**
     * @param array $body
     * @return $this
     */
    public function setBody(array $body): self
    {
        $this->body = $body;
        $this->adapter->setBody($body);

        return $this;
    }

    /**
     * @param array $cookies
     * @return $this
     */
    public function setCookies(array $cookies): self
    {
        $this->cookies = $cookies;
        $this->adapter->setCookies($cookies);

        return $this;
    }

    /**
     * @param string $domain
     * @return $this
     */
    public function setDomain(string $domain): self
    {
        $this->domain = $domain;
        $this->adapter->setDomain($domain);

        return $this;
    }

    /**
     * @param array $query
     * @return $this
     */
    public function setQuery(array $query): self
    {
        $this->query = $query;
        $this->adapter->setQuery($query);

        return $this;
    }

    /**
     * @param string $method
     * @return $this
     */
    public function setMethod(string $method): self
    {
        $this->method = $method;
        $this->adapter->setMethod($method);

        return $this;
    }

    /**
     * @param string $protocol
     * @return $this
     */
    public function setProtocol(string $protocol): self
    {
        $this->protocol = $protocol;
        $this->adapter->setProtocol($protocol);

        return $this;
    }

    /**
     * @param string $method
     * @param array $args
     * @return mixed
     */
    public function __call(string $method, array $args)
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
    public function offsetExists($offset): bool
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
    public function count(): int
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
     */
    public function __set($name, $value)
    {
        $this->offsetSet($name, $value);
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return (string)$this->responseContent;
    }
}
