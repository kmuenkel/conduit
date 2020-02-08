<?php

namespace Conduit\Middleware;

use Conduit\Adapters\Adapter;
use Psr\Http\Message\ResponseInterface;

/**
 * Class LoggingMiddleware
 * @package Conduit\Middleware
 */
class LoggingMiddleware
{
    /**
     * @var callable
     */
    protected $logger;

    /**
     * @var int
     */
    protected static $truncate = 0;

    /**
     * LoggingMiddleware constructor.
     * @param callable|null $logger
     */
    public function __construct(callable $logger = null)
    {
        $truncate = config('conduit.middleware.'.static::class.'.truncate');
        $truncate = is_null($truncate) ? config('conduit.middleware.'.self::class.'.truncate', 0) : $truncate;
        self::setTruncate($truncate);

        $this->setLogger($logger ?: function (Adapter $adapter) {
            $request = parse_adapter_request($adapter);
            $response = parse_adapter_response($adapter);
            $truncate = self::getTruncate();

            if ($truncate && strlen($response['body']) > $truncate) {
                $response['body'] = substr($response['body'], 0, $truncate).'...';
            }

            error_log(print_r(compact('request', 'response'), true));
        });
    }

    /**
     * @param int $truncate
     */
    public static function setTruncate(int $truncate)
    {
        self::$truncate = $truncate;
    }

    /**
     * @return int
     */
    public static function getTruncate()
    {
        return self::$truncate;
    }

    /**
     * @param callable $logger
     */
    public function setLogger(callable $logger)
    {
        $this->logger = $logger;
    }

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
            ($this->logger)($adapter);
        }

        return $results;
    }
}
