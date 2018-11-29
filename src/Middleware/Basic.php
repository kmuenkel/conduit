<?php

namespace Conduit\Middleware;

use Psr\Http\Message\RequestInterface;

class Basic extends BaseMiddleware
{
    /**
     * @return callable
     */
    public function getMiddleware() : callable
    {
        $defaults = ['cache_key' => $this->guessCacheKey()];
        $config = array_merge($defaults, $this->config);
        
        $middleware = function (callable $handler) use ($config) {
            $chainLink = function (RequestInterface $request, array $options) use ($handler, $config) {
                if (!($token = session($config['cache_key']))
                    && array_key_exists('username', $config)
                    && array_key_exists('password', $config)
                ) {
                    $token = base64_encode($config['username'].':'.$config['password']);
                    session([$config['cache_key'] => $token]);
                }
                
                return $token ?
                    $handler($request->withHeader('Authorization', "Basic $token"), $options)
                    : $handler($request, $options);
            };
            
            return $chainLink;
        };
        
        return $middleware;
    }
}
