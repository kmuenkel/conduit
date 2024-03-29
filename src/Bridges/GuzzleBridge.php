<?php

namespace Conduit\Bridges;

use RuntimeException;
use GuzzleHttp\Psr7\Request;
use Conduit\Adapters\Adapter;
use Conduit\Endpoints\Endpoint;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Cookie\{CookieJar, SetCookie};
use GuzzleHttp\{Client, HandlerStack, RequestOptions};

/**
 * Class GuzzleBridge
 * @package Conduit\Bridges
 */
class GuzzleBridge implements Bridge
{
    /**
     * @var Adapter
     */
    protected Adapter $adapter;

    /**
     * @var Client
     */
    protected Client $client;

    /**
     * @var array
     */
    protected array $options = [];

    /**
     * @var array
     */
    protected array $config = [];

    /**
     * @var CookieJar
     */
    protected CookieJar $cookies;

    /**
     * @var CookieJar|null
     */
    protected static ?CookieJar $permanentCookies = null;

    /**
     * @var bool
     */
    protected static bool $keepCookies = true;

    /**
     * @var RequestException|null
     */
    protected ?RequestException $error = null;

    /**
     * GuzzleBridge constructor.
     * @param Adapter $adapter
     * @param array $config
     */
    public function __construct(Adapter $adapter, array $config = [])
    {
        $this->config = $config;
        $this->config['strict_mode'] = $this->config['strict_mode'] ?? false;

        $this->setAdapter($adapter);
        $client = $this->newClientInstance();
        $this->setClient($client);

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
     * {@inheritDoc}
     */
    public function send()
    {
        $request = $this->makeRequest();
        $this->error = null;

        try {
            $response = $this->client->send($request, $this->options);
        } catch (RequestException $error) {
            $response = $error->getResponse();
            $this->error = $error;
        }

        $this->adapter->setCookies($this->cookies->toArray());

        return $response;
    }

    /**
     * @return RequestException|RuntimeException|null
     */
    public function getError(): ?RuntimeException
    {
        return $this->error;
    }

    /**
     * {@inheritDoc}
     */
    public function setAdapter(Adapter $adapter): Bridge
    {
        $this->adapter = $adapter;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function getAdapter(): Adapter
    {
        return $this->adapter;
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
        return app(Client::class, ['config' => $this->config]);
    }

    /**
     * @return CookieJar
     */
    protected function getCookies()
    {
        $cookiesPermitted = (bool)($this->config['cookies'] ?? true);

        if ($cookiesPermitted) {
            /** @var CookieJar|string[]|SetCookie[] $cookies */
            $cookies = $this->adapter->getCookies();
            $cookies = normalize_cookies($cookies, $this->config['strict_mode']);

            foreach ($cookies as $cookie) {
                $this->cookies->setCookie($cookie);
            }
        }

        return $this->cookies;
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
    public function getBaseUrl()
    {
        $protocol = $this->adapter->getProtocol();
        $domain = $this->adapter->getDomain();
        $url = (($protocol && $domain) ? "$protocol://" : '').$domain;

        return $url;
    }

    /**
     * @return Request
     */
    public function makeRequest()
    {
        $method = $this->adapter->getMethod();
        $uri = $this->getBaseUrl().'/'.$this->adapter->getRoute();
        $headers = $this->adapter->getHeaders();
        $body = $this->adapter->getBody();

        $request = app(Request::class, compact('method', 'uri', 'headers'));

        $this->options = array_merge($this->options, [
            RequestOptions::QUERY => $this->adapter->getQuery(),
            RequestOptions::COOKIES => $this->getCookies()
        ]);

        $contentType = $headers['content-type'] ?? Endpoint::CONTENT_TYPE_URL_ENCODED_FORM;
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
