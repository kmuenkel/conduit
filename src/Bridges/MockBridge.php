<?php

namespace Conduit\Bridges;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Cookie\SetCookie;
use GuzzleHttp\Cookie\CookieJar;
use Evaluator\Parsers\ConfigParser;

/**
 * Class MockBridge
 * @package Conduit\Bridges
 */
class MockBridge extends GuzzleBridge
{
    /**
     * @var Response[]
     */
    protected static $responses = [];

    /**
     * @var array[]
     */
    protected static $conditionSets = [];

    /**
     * @var null
     */
    protected $error = null;

    /**
     * {@inheritDoc}
     */
    public function send()
    {
        $response = $this->findResponse();
        $this->adapter->setCookies($this->cookies->toArray());

        return $response;
    }

    /**
     * @return RequestException|null
     */
    public function getError(): ?RuntimeException
    {
        return $this->error;
    }

    /**
     * @param Response $response
     * @param array $conditions
     */
    public static function setUpResponse(Response $response, array $conditions)
    {
        $conditions['all'] = [
            'comparator' => 'all',
            'value2' => array_keys($conditions)
        ];
        self::$conditionSets[] = self::normalizeConditions($conditions);
        self::$responses[] = $response;
    }

    /**
     * @param int $status
     * @param array $headers
     * @param string|mixed|null $body
     * @param CookieJar|string[]|SetCookie[] $cookies
     * @return Response
     */
    public static function makeResponse($status = 200, $headers = [], $body = null, $cookies = [])
    {
        $cookies = normalize_cookies($cookies);
        /** @var SetCookie $cookie */
        foreach ($cookies as $cookie) {
            $headers['set-cookie'][] = (string)$cookie;
        }

        $response = app(Response::class, compact('status', 'headers', 'body'));

        return $response;
    }

    /**
     * @param array $conditions
     * @return array
     */
    protected static function normalizeConditions(array $conditions)
    {
        $reservedKeys = [
            'reference1',
            'reference2',
            'value1',
            'value2',
            'child1',
            'child2',
            'comparator'
        ];

        $rules = [];

        foreach ($conditions as $name => $rule) {
            if (!is_array($rule) || !empty(array_diff(array_keys($rule), $reservedKeys))) {
                $rule = [
                    'reference1' => $name,
                    'value2' => $rule
                ];
            }

            $rule['comparator'] = $rule['comparator'] ?? '==';

            $rules[$name] = $rule;
        }

        return $rules;
    }

    /**
     * @return Response
     */
    public function findResponse()
    {
        $data = parse_adapter_request($this->adapter);
        $evaluator = app(ConfigParser::class, compact('data'));

        foreach (self::$conditionSets as $index => $conditions) {
            $evaluator->setRules($conditions);
            if ($evaluator->evaluate('all')) {
                return self::$responses[$index];
            }
        }

        return $this->makeResponse();
    }
}
