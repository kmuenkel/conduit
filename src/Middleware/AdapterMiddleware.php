<?php

namespace Conduit\Middleware;

interface AdapterMiddleware
{
    /**
     * AdapterMiddleware constructor.
     * @param array $config
     */
    public function __construct(array $config = []);
    
    /**
     * @return callable
     */
    public function getMiddleware() : callable;
}
