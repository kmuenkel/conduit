<?php

namespace Conduit\Adapters;

use DateTime;
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
    protected ?ResponseInterface $response = null;

    /**
     * @var string|null
     */
    protected ?string $bridgeName = null;

    /**
     * @var Bridge|null
     */
    protected ?Bridge $bridge = null;

    /**
     * @var string
     */
    protected string $protocol = self::PROTOCOLS[0];

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
    protected string $method = self::METHODS[0];

    /**
     * @var array
     */
    protected array $query = [];

    /**
     * @var mixed|array
     */
    protected $body = [];

    /**
     * @var array
     */
    protected array $headers = [];

    /**
     * @var array
     */
    protected array $cookies = [];

    /**
     * @var DateTime|null
     */
    protected ?DateTime $sentAt = null;

    /**
     * @var DateTime|null
     */
    protected ?DateTime $receivedAt = null;

    /**
     * @param string|null $bridgeName
     * @param array $config
     */
    public function __construct(string $bridgeName = null, array $config = [])
    {
        $this->bridgeName = $bridgeName ?: ($this->bridgeName ?: config('conduit.default_bridge'));
        $bridge = $this->newBridgeInstance($config);
        $this->setBridge($bridge);
    }

    /**
     * @param array $bridgeConfig
     * @return Bridge
     */
    public function newBridgeInstance(array $bridgeConfig = []): Bridge
    {
        $bridgeConfig = $bridgeConfig ?: config("conduit.bridges.$this->bridgeName");
        $config = $bridgeConfig['config'] ?? [];
        $bridge = new $bridgeConfig['bridge']($this, $config);

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
    public function setBridge(Bridge $bridge): self
    {
        $this->bridge = $bridge;

        return $this;
    }

    /**
     * @return Bridge|null
     */
    public function getBridge(): ?Bridge
    {
        return $this->bridge;
    }

    /**
     * @param string $protocol
     * @return $this
     */
    public function setProtocol(string $protocol): self
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
    public function setMethod(string $method): self
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
    public function getProtocol(): string
    {
        return $this->protocol;
    }

    /**
     * @param string $domain
     * @return $this
     */
    public function setDomain(string $domain): self
    {
        $this->domain = $domain;

        return $this;
    }

    /**
     * @return string
     */
    public function getDomain(): string
    {
        return $this->domain;
    }

    /**
     * @param string $route
     * @return $this
     */
    public function setRoute(string $route): self
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
     * @return $this
     */
    public function setQuery(array $query): self
    {
        $this->query = $query;

        return $this;
    }

    /**
     * @return array
     */
    public function getQuery(): array
    {
        return $this->query;
    }

    /**
     * @param mixed|array $body
     * @return $this
     */
    public function setBody($body): self
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
    public function setCookies(array $cookies): self
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
    public function send(): ResponseInterface
    {
        /**
         * @param Adapter $adapter
         * @return ResponseInterface
         */
        $final = function (Adapter $adapter) {
            $this->sentAt = new DateTime();
            $results = $adapter->getBridge()->send();
            $this->receivedAt = new DateTime();

            return $results;
        };

        return $this->response = $this->handle($this, $final);
    }

    /**
     * @return ResponseInterface|null
     */
    public function getResponse(): ?ResponseInterface
    {
        return $this->response;
    }

    /**
     * @param ResponseInterface $response
     * @return $this
     */
    public function setResponse(ResponseInterface $response): self
    {
        $this->response = $response;

        return $this;
    }

    /**
     * @return DateTime|null
     */
    public function getSentAt(): ?DateTime
    {
        return $this->sentAt;
    }

    /**
     * @return DateTime|null
     */
    public function getReceivedAt(): ?DateTime
    {
        return $this->receivedAt;
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
