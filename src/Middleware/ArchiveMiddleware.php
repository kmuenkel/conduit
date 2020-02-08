<?php

namespace Conduit\Middleware;

use Conduit\Adapters\Adapter;
use Psr\Http\Message\ResponseInterface;

/**
 * Class TestingMiddleware
 * @package Conduit\Middleware
 */
class ArchiveMiddleware
{
    /**
     * @var Adapter[]
     */
    protected static $logBucket = [];

    /**
     * @param Adapter $adapter
     * @param callable $next
     * @return ResponseInterface
     */
    public function __invoke(Adapter $adapter, callable $next)
    {
        $results = null;

        try {
            /** @var ResponseInterface $results */
            $results = $next($adapter);
        } finally {
            $results && $adapter->setResponse($results);
            self::$logBucket[] = clone $adapter;
        }

        return $results;
    }

    /**
     * @return Adapter[]
     */
    public static function getLogBucket(): array
    {
        return self::$logBucket;
    }

    /**
     * @return Adapter[]
     */
    public static function flushLogBucket(): array
    {
        $logBucket = self::getLogBucket();
        self::$logBucket = [];

        return $logBucket;
    }

    /**
     * @return array|Adapter[]
     */
    public static function toArray()
    {
        $logBucket = self::getLogBucket();
        array_walk($logBucket, function (Adapter &$adapter) {
            $request = parse_adapter_request($adapter);
            $response = parse_adapter_response($adapter);
            $adapter = compact('request', 'response');
        });

        return $logBucket;
    }
}
