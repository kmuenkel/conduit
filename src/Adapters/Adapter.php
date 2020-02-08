<?php

namespace Conduit\Adapters;

use Conduit\Bridges\Bridge;
use InvalidArgumentException;
use HandlerStack\Traits\HandlerStack;
use Psr\Http\Message\ResponseInterface;

/**
 * Class Adapter
 * @package Conduit\Adapters
 */
class Adapter
{
    use HandlerStack;

    const METHODS = [
        'GET',
        'POST',
        'PUT',
        'PATCH',
        'DELETE',
        'OPTIONS'
    ];

    const PROTOCOLS = [
        'http',
        'https',
        'ftp',
        'sftp',
        'smtp'
    ];

    /**
     * @var ResponseInterface|null
     */
    protected $response = null;

    /**
     * @var string|null
     */
    protected $bridgeName = null;

    /**
     * @var Bridge
     */
    protected $bridge;

    /**
     * @var string
     */
    protected $protocol = self::PROTOCOLS[0];

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
    protected $method = self::METHODS[0];

    /**
     * @var array
     */
    protected $query = [];

    /**
     * @var mixed|array
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
     * Adapter constructor.
     * @param string|null $bridgeName
     */
    public function __construct($bridgeName = null)
    {
        $this->bridgeName = $bridgeName ?: ($this->bridgeName ?: config('conduit.default_bridge'));
        $bridge = $this->newBridgeInstance();
        $this->setBridge($bridge);
    }

    /**
     * @return Bridge
     */
    public function newBridgeInstance()
    {
        $bridgeConfig = config("conduit.bridges.$this->bridgeName");
        $config = $bridgeConfig['config'] ?? [];
        $bridge = app($bridgeConfig['bridge'], ['adapter' => $this, 'config' => $config]);

        if (!$bridge instanceof Bridge) {
            throw new InvalidArgumentException("Bridge must be an instance of '".Bridge::class."'."
                .get_class($bridge)."' given.");
        }

        return $bridge;
    }

    /**
     * @param Bridge $bridge
     * @return $this
     */
    public function setBridge(Bridge $bridge): Adapter
    {
        $this->bridge = $bridge;

        return $this;
    }

    /**
     * @return Bridge|null
     */
    public function getBridge()
    {
        return $this->bridge;
    }

    /**
     * @param string $protocol
     * @return Adapter
     */
    public function setProtocol(string $protocol): Adapter
    {
        $protocol = strtolower($protocol);
        if (!in_array($protocol, self::PROTOCOLS)) {
            throw new InvalidArgumentException('Method must be one of the following: '.implode(', ', self::PROTOCOLS)
                .". '$protocol' given.");
        }
        $this->protocol = $protocol;

        return $this;
    }

    /**
     * @param string $method
     * @return $this
     */
    public function setMethod(string $method): Adapter
    {
        $method = strtoupper($method);
        if (!in_array($method, self::METHODS)) {
            throw new InvalidArgumentException('Method must be one of the following: '.implode(', ', self::METHODS));
        }

        $this->method = $method;

        return $this;
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
    public function getProtocol()
    {
        return $this->protocol;
    }

    /**
     * @param string $domain
     * @return Adapter
     */
    public function setDomain(string $domain): Adapter
    {
        $this->domain = $domain;

        return $this;
    }

    /**
     * @return string
     */
    public function getDomain()
    {
        return $this->domain;
    }

    /**
     * @param string $route
     * @return Adapter
     */
    public function setRoute(string $route): Adapter
    {
        $this->route = $route;

        return $this;
    }

    /**
     * @return string
     */
    public function getRoute(): string
    {
        return $this->route;
    }

    /**
     * @param array $query
     * @return Adapter
     */
    public function setQuery(array $query)
    {
        $this->query = $query;

        return $this;
    }

    /**
     * @return array
     */
    public function getQuery()
    {
        return $this->query;
    }

    /**
     * @param mixed|array $body
     * @return $this
     */
    public function setBody($body)
    {
        $this->body = $body;

        return $this;
    }

    /**
     * @return mixed|array
     */
    public function getBody()
    {
        return $this->body;
    }

    /**
     * @param array $headers
     * @return $this
     */
    public function setHeaders(array $headers)
    {
        $headers = array_change_key_case($headers);
        $this->headers = $headers;

        return $this;
    }

    /**
     * @return array
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * @param array $cookies
     * @return $this
     */
    public function setCookies(array $cookies)
    {
        $this->cookies = $cookies;

        return $this;
    }

    /**
     * @return array
     */
    public function getCookies(): array
    {
        return $this->cookies;
    }

    /**
     * @return ResponseInterface
     */
    public function send()
    {
        /**
         * @param Adapter $adapter
         * @return ResponseInterface
         */
        $final = function (Adapter $adapter) {
            $results = $adapter->getBridge()->send();

            return $results;
        };

        return $this->response = $this->handle($this, $final);
    }

    /**
     * @return ResponseInterface|null
     */
    public function getResponse()
    {
        return $this->response;
    }

    /**
     * @param ResponseInterface $response
     * @return $this
     */
    public function setResponse(ResponseInterface $response)
    {
        $this->response = $response;

        return $this;
    }

    /**
     * @void
     */
    public function __clone()
    {
        $this->bridge = clone $this->bridge;
        $this->bridge->setAdapter($this);
    }
}
