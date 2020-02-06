<?php

namespace Conduit\Bridges;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use InvalidArgumentException;
use Conduit\Adapters\Adapter;
use GuzzleHttp\RequestOptions;
use Conduit\Endpoints\Endpoint;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;

/**
 * Class GuzzleBridge
 * @package Conduit\Bridges
 */
class GuzzleBridge implements Bridge
{
    /**
     * @var Adapter
     */
    protected $adapter;

    /**
     * @var Client
     */
    protected $client;

    /**
     * @var array
     */
    protected $options = [];

    /**
     * @var array
     */
    protected $config = [];

    /**
     * @var CookieJar
     */
    protected $cookies;

    /**
     * @var CookieJar|null
     */
    protected static $permanentCookies = null;

    /**
     * @var bool
     */
    protected static $keepCookies = true;

    /**
     * GuzzleBridge constructor.
     * @param Adapter $adapter
     * @param array $config
     */
    public function __construct(Adapter $adapter, array $config = [])
    {
        $this->setAdapter($adapter);
        $this->config = $config;
        $this->config['strict_mode'] = $this->config['strict_mode'] ?? false;
        $this->refresh();

        self::$permanentCookies = self::$keepCookies ? self::$permanentCookies : null;
        self::$permanentCookies = $this->cookies = self::$permanentCookies ?:
            app(CookieJar::class, ['strictMode' => $this->config['strict_mode'], 'cookieArray' => []]);
    }

    /**
     * @param bool $keep
     */
    public static function keepCookies($keep = true)
    {
        self::$keepCookies = $keep;
    }

    /**
     * @void
     */
    protected function refresh()
    {
        $client = $this->newClientInstance();
        $this->setClient($client);
    }

    /**
     * {@inheritDoc}
     */
    public function send()
    {
        $request = $this->makeRequest();

        return $this->client->send($request, $this->options);
    }

    /**
     * {@inheritDoc}
     */
    public function setAdapter(Adapter $adapter): Bridge
    {
        $this->adapter = $adapter;
        $this->refresh();

        return $this;
    }

    /**
     * @param callable $handler
     * @return $this
     */
    public function setHandler(callable $handler)
    {
        $this->config['handler'] = HandlerStack::create($handler);

        return $this;
    }

    /**
     * @return Client
     */
    public function newClientInstance()
    {
        $this->config['base_uri'] = $this->getUrl();

        return app(Client::class, ['config' => $this->config]);
    }

    /**
     * @return CookieJar
     */
    protected function getCookies()
    {
        $cookiesPermitted = (bool)$this->config['cookies'] ?? true;

        if ($cookiesPermitted) {
            /** @var CookieJar|string[]|SetCookie[] $cookies */
            $cookies = $this->adapter->getCookies();
            $cookies = $this->normalizeCookies($cookies, $this->config['strict_mode']);

            foreach ($cookies as $cookie) {
                $this->cookies->setCookie($cookie);
            }
        }

        return $this->cookies;
    }

    /**
     * @param CookieJar|string[]|SetCookie[] $cookies
     * @param bool $strictMode
     * @return CookieJar
     */
    public function normalizeCookies($cookies, $strictMode = false)
    {
        if (!($cookies instanceof CookieJar)) {
            foreach ($cookies as $index => $cookie) {
                if (is_string($cookie)) {
                    $cookies[$index] = SetCookie::fromString($cookie);
                } elseif (is_array($cookie)) {
                    $cookies[$index] = app(SetCookie::class, ['data' => $cookie]);
                } elseif (!($cookie instanceof SetCookie)) {
                    throw new InvalidArgumentException('Cookie must be a string, array, or instance of '
                        .SetCookie::class.'.');
                }
            }

            $cookies = app(CookieJar::class, ['strictMode' => $strictMode, 'cookieArray' => $cookies]);
        }

        return $cookies;
    }

    /**
     * @param Client $client
     * @return $this
     */
    public function setClient(Client $client)
    {
        $this->client = $client;

        return $this;
    }

    /**
     * @return string
     */
    public function getUrl()
    {
        $protocol = $this->adapter->getProtocol();
        $domain = $this->adapter->getDomain();
        $url = ($protocol ? "$protocol://" : '').$domain;

        return $url;
    }

    /**
     * @return Request
     */
    protected function makeRequest()
    {
        $method = $this->adapter->getMethod();
        $uri = $this->adapter->getRoute();
        $headers = $this->adapter->getHeaders();
        $body = $this->adapter->getBody();

        $request = app(Request::class, compact('method', 'uri', 'headers'));

        $this->options = array_merge($this->options, [
            RequestOptions::QUERY => $this->adapter->getQuery(),
            RequestOptions::COOKIES => $this->getCookies()
        ]);

        $contentType = $headers['accept'] ?? Endpoint::CONTENT_TYPE_URL_ENCODED_FORM;
        switch ($contentType) {
            case Endpoint::CONTENT_TYPE_JSON:
                $this->options[RequestOptions::JSON] = $body;

                break;
            case Endpoint::CONTENT_TYPE_URL_ENCODED_FORM:
                $this->options[RequestOptions::FORM_PARAMS] = $body;

                break;
            case Endpoint::CONTENT_TYPE_MULTIPART_FORM:
                $this->options[RequestOptions::MULTIPART] = [];
                foreach ($body as $name => $contents) {
                    if (is_int($name) && is_array($contents)) {
                        $name = $contents['name'];
                        $contents = $contents['contents'];
                    }
                    $this->options[RequestOptions::MULTIPART][] = compact('name', 'contents');
                }

                break;
        }

        return $request;
    }
}
