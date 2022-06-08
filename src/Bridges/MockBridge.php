<?php

namespace Conduit\Bridges;

use GuzzleHttp\Psr7\Response;
use Evaluator\Parsers\ConfigParser;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Cookie\{SetCookie, CookieJar};

/**
 * Class MockBridge
 * @package Conduit\Bridges
 */
class MockBridge extends GuzzleBridge
{
    /**
     * @var Response[]
     */
    public static $responses = [];

    /**
     * @var callable[]
     */
    protected static $events = [];

    /**
     * @var array[]
     */
    protected static $conditionSets = [];

    /**
     * @var RequestException|null
     */
    protected ?RequestException $error = null;

    /**
     * {@inheritDoc}
     */
    public function send()
    {
        $responseIndex = $this->findResponse();
        $response = is_null($responseIndex) ? $this->makeResponse() : static::$responses[$responseIndex];
        $this->adapter->setCookies($this->cookies->toArray());
        $this->adapter->setResponse($response);
        !is_null($responseIndex) && static::$events[$responseIndex]($this->adapter);

        return $response;
    }

    /**
     * @return RequestException|null
     */
    public function getError(): ?RequestException
    {
        return $this->error;
    }

    /**
     * @param Response $response
     * @param array $conditions
     * @param callable|null $event
     */
    public static function setUpResponse(Response $response, array $conditions, callable $event = null)
    {
        $conditions['all'] = [
            'comparator' => 'all',
            'value2' => array_keys($conditions)
        ];
        static::$conditionSets[] = static::normalizeConditions($conditions);
        static::$responses[] = $response;
        static::$events[] = $event ?: fn () => null;
    }

    /**
     * @return void
     */
    public static function resetResponses()
    {
        static::$conditionSets = static::$responses = static::$events = [];
    }

    /**
     * @param int $status
     * @param array $headers
     * @param string|mixed|null $body
     * @param CookieJar|string[]|SetCookie[] $cookies
     * @return Response
     */
    public static function makeResponse($status = 200, $headers = [], $body = null, $cookies = []): Response
    {
        $cookies = normalize_cookies($cookies);

        if ($cookies instanceof CookieJar) {
            $cookieNames = array_column($cookies->toArray(), 'Name');
            $cookies = array_map(fn (string $name) => $cookies->getCookieByName($name), $cookieNames);
        }

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
    protected static function normalizeConditions(array $conditions): array
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
     * @return ?int
     */
    public function findResponse(): ?int
    {
        $data = parse_adapter_request($this->adapter);
        $evaluator = app(ConfigParser::class, compact('data'));

        foreach (static::$conditionSets as $index => $conditions) {
            $evaluator->setRules($conditions);
            if ($evaluator->evaluate('all')) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @return array
     */
    public function __serialize(): array
    {
        return [
            'this' => get_object_vars($this),
            'static' => [
                'responses' => array_map(function (Response $response) {
                    return [
                        'status' => $response->getStatusCode(),
                        'headers' => $response->getHeaders(),
                        'body' => (string)$response->getBody()
                    ];
                }, static::$responses),
                'events' => array_map('\Opis\Closure\serialize', static::$events),
                'conditionSets' => static::$conditionSets,
                'permanentCookies' => static::$permanentCookies,
                'keepCookies' => static::$keepCookies
            ]
        ];
    }

    /**
     * @param array $data
     * @return void
     */
    public function __unserialize(array $data): void
    {
        array_map(fn ($property, string $name) => $this->$name = $property, $data['this'], array_keys($data['this']));

        static::$responses = array_map(function (array $response) {
            return static::makeResponse($response['status'], $response['headers'], $response['body']);
        }, $data['static']['responses']);
        static::$events = array_map('\Opis\Closure\unserialize', $data['static']['events']);
        static::$conditionSets = $data['static']['conditionSets'];
        static::$permanentCookies = $data['static']['permanentCookies'];
        static::$keepCookies = $data['static']['keepCookies'];
    }
}
