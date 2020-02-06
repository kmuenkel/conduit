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
     * LoggingMiddleware constructor.
     * @param callable|null $logger
     */
    public function __construct(callable $logger = null)
    {
        $this->setLogger($logger ?: function (Adapter $adapter, ResponseInterface $response) {
            error_log(print_r(compact('adapter', 'response'), true));
        });
    }

    /**
     * @param callable $logger
     */
    public function setLogger(callable $logger)
    {
        $this->logger = $logger;
    }

    //TODO: Parse the request details out of the Adapter

    //TODO: Parse the response details out of the results

    /**
     * @param Adapter $adapter
     * @param callable $next
     * @return ResponseInterface
     */
    public function __invoke(Adapter $adapter, callable $next)
    {
        $results = null;

        try {
            $results = $next($adapter);
        } finally {
            ($this->logger)($adapter, $results);
        }

        return $results;
    }
}
