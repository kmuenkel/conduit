<?php

use Conduit\Adapters\Adapter;
use Conduit\Transformers\ResponseStruct;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use GuzzleHttp\Psr7\Request;
use http\QueryString;
use Psr\Http\Message\ResponseInterface;

if (!function_exists('parse_uri')) {
    /**
     * @param string $url
     * @return array
     */
    function parse_uri($url) : array
    {
        $parts = parse_url($url);

        $queryString = '';
        if (array_key_exists('query', $parts)) {
            $query = [];
            parse_str(urldecode($parts['query']), $query);
            $parts['query'] = $query;
            $queryString .= '?'.urldecode(app(QueryString::class)->set($parts['query'])->toString());
        }

        $parts['full'] = (isset($parts['scheme']) ? $parts['scheme'].'://' : '')
            . ($parts['host'] ?? '')
            . ($parts['path'] ?? '')
            . $queryString;

        return $parts;
    }
}

if (!function_exists('config')) {
    /**
     * @param string $key
     * @param mixed|null $default
     * @return mixed
     */
    function config(string $key, $default = null) {
        /** @var array $configs */
        $config = require_once __DIR__."/../config/conduit.php";

        $keyParts = explode('.', $key);
        $root = array_shift($keyParts);
        if ($root != 'conduit') {
            return $default;
        }

        foreach ($keyParts as $keyPart) {
            if (!isset($config[$keyPart])) {
                $config = $default;

                break;
            }

            $config = $config[$keyPart];
        }

        return $config;
    }
}

if (!function_exists('app')) {
    /**
     * @param string $className
     * @param array $args
     * @return mixed
     */
    function app($className, array $args = []) {
        $args = array_values(compile_arguments($className, $args));

        return new $className(...$args);
    }
}


if (!function_exists('compile_arguments')) {
    /**
     * @param string|string[] $function
     * @param array $args
     * @return array|false
     */
    function compile_arguments($function, array $args = [])
    {
        $function = class_exists($function) ? [$function, '__construct'] : $function;
        [$names, $defaults] = get_parameter_definitions($function);
        $order = array_flip($names);
        $definitions = array_merge($order, $defaults);
        $given = array_slice($names, 0, count($args));
        $args = has_numeric_keys($args) ? array_combine($given, $args) : array_merge($definitions, $args);

        return $args;
    }
}

if (!function_exists('get_parameter_definitions')) {
    /**
     * @param string|string[] $function
     * @return array
     */
    function get_parameter_definitions($function)
    {
        list($class, $function) = array_pad((array)$function, -2, null);

        $defaults = $parameterNames = [];

        try {
            $reflection = $class ? new ReflectionMethod($class, $function) : new ReflectionFunction($function);

            /** @var ReflectionParameter $param */
            foreach ($reflection->getParameters() as $param) {
                if ($param->isDefaultValueAvailable()) {
                    $defaults[$param->name] = $param->getDefaultValue();
                }

                $parameterNames[] = $param->name;

            }
        } catch (ReflectionException $e) {
            //
        }

        return [$parameterNames, $defaults];
    }
}

if (!function_exists('parse_adapter_request')) {
    /**
     * @param Adapter $adapter
     * @return array
     */
    function parse_adapter_request(Adapter $adapter)
    {
        $request = [
            'method' => $adapter->getMethod(),
            'protocol' => $adapter->getProtocol(),
            'domain' => $adapter->getDomain(),
            'route' => $adapter->getRoute(),
            'query' => $adapter->getQuery(),
            'body' => $adapter->getBody(),
            'headers' => $adapter->getHeaders(),
            'cookies' => normalize_cookies($adapter->getCookies(), false)->toArray()
        ];
        $request['cookies'] = array_combine(array_column($request['cookies'], 'Name'), $request['cookies']);

        return $request;
    }
}

if (!function_exists('normalize_cookies')) {
    /**
     * @param CookieJar|string[]|SetCookie[] $cookies
     * @param bool $strictMode
     * @return CookieJar
     */
    function normalize_cookies($cookies, $strictMode = false)
    {
        if (!($cookies instanceof CookieJar)) {
            foreach ($cookies as $index => $cookie) {
                if (is_string($cookie)) {
                    $cookies[$index] = SetCookie::fromString($cookie);
                } elseif (is_array($cookie)) {
                    $cookies[$index] = app(SetCookie::class, ['data' => $cookie]);
                } elseif (!($cookie instanceof SetCookie)) {
                    throw new InvalidArgumentException('Cookie must be a string, array, or instance of '
                        . SetCookie::class . '.');
                }
            }

            $cookies = app(CookieJar::class, ['strictMode' => $strictMode, 'cookieArray' => $cookies]);
        }

        return $cookies;
    }
}

if (!function_exists('parse_adapter_response')) {
    /**
     * @param Adapter $adapter
     * @return array
     */
    function parse_adapter_response(Adapter $adapter)
    {
        if (!($response = $adapter->getResponse())) {
            return [
                'code' => 0,
                'headers' => [],
                'cookies' => [],
                'body' => null
            ];
        }

        $headers = $response->getHeaders();
        $headers = array_map(function (array $header) {
            return !($index = array_key_last($header)) ? $header[$index] : $header;
        }, $headers);

        $protocol = $adapter->getProtocol();
        $domain = $adapter->getDomain();
        $route = $adapter->getRoute();
        $uri = (($protocol && $domain) ? "$protocol://" : '') . $domain . ($route ? "/$route" : '');

        $method = $adapter->getMethod();
        $request = app(Request::class, compact('method', 'uri', 'headers'));
        $cookies = app(CookieJar::class, ['strictMode' => false, 'cookieArray' => []]);
        $cookies->extractCookies($request, $response);
        $cookies = $cookies->toArray();
        $cookies = array_combine(array_column($cookies, 'Name'), $cookies);

        $body = (string)$response->getBody();

        $response = [
            'code' => $response->getStatusCode(),
            'headers' => $headers,
            'cookies' => $cookies,
            'body' => $body
        ];

        return $response;
    }
}
