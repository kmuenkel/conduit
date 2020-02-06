<?php

namespace Conduit\Bridges;

use Conduit\Adapters\Adapter;
use GuzzleHttp\Psr7\Response;
use Evaluator\Parsers\ConfigParser;
use GuzzleHttp\Cookie\SetCookie;
use GuzzleHttp\Cookie\CookieJar;

/**
 * Class MockBridge
 * @package Conduit\Bridges
 */
class MockBridge extends GuzzleBridge
{
    const ALL = 'all';
    const ANY = 'any';

    /**
     * @var Response[]
     */
    protected $responses = [];

    /**
     * @var array[]
     */
    protected $conditionSets = [];

    /**
     * @var bool
     */
    protected static $keepCookies = false;

    /**
     * {@inheritDoc}
     */
    public function send()
    {
        return $this->findResponse();
    }

    /**
     * @param Response $response
     * @param array $conditions
     * @return $this
     */
    public function setUpResponse(Response $response, array $conditions)
    {
        $conditions[self::ALL] = array_keys($conditions);
        $this->conditionSets = $conditions;
        $this->responses[] = $response;

        return $this;
    }

    /**
     * @param int $status
     * @param array $headers
     * @param string|mixed|null $body
     * @param CookieJar|string[]|SetCookie[] $cookies
     * @return Response
     */
    public function makeResponse($status = 200, $headers = [], $body = null, $cookies = [])
    {
        $cookies = $this->normalizeCookies($cookies, $this->config['strict_mode']);
        /** @var SetCookie $cookie */
        foreach ($cookies as $cookie) {
            $headers['set-cookie'][] = (string)$cookie;
        }

        $response = app(Response::class, compact('status', 'headers', 'body'));

        return $response;
    }

    /**
     * @return Response
     */
    public function findResponse()
    {
        $data = [
            'method' => $this->adapter->getMethod(),
            'protocol' => $this->adapter->getProtocol(),
            'domain' => $this->adapter->getDomain(),
            'route' => $this->adapter->getRoute(),
            'query' => $this->adapter->getQuery(),
            'body' => $this->adapter->getBody(),
            'headers' => $this->adapter->getHeaders(),
            'cookies' => $this->normalizeCookies($this->adapter->getCookies(), $this->config['strict_mode'])->toArray()
        ];

        $evaluator = app(ConfigParser::class, compact('data'));

        foreach ($this->conditionSets as $index => $conditions) {
            $evaluator->setRules($conditions);
            if ($evaluator->evaluate(self::ALL)) {
                return $this->responses[$index];
            }
        }

        return $this->makeResponse();
    }

    public function setDefault()
    {
        $status = 200;
        $headers = [];
        $body = null;
        $response = app(Response::class, compact('status', 'headers', 'body'));
        $cookies = app(CookieJar::class, ['strictMode' => $this->config['strict_mode'], 'cookieArray' => []]);
    }

    /**
     * {@inheritDoc}
     */
    public function setAdapter(Adapter $adapter): Bridge
    {
        $this->adapter = $adapter;

        return $this;
    }
}
