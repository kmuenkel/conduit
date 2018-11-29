<?php

namespace Conduit\Middleware;

use Psr\Http\Message\RequestInterface;

class Jwt extends BaseMiddleware
{
    /**
     * @return callable
     */
    public function getMiddleware() : callable
    {
        $defaults = [
            'cache_key' => $this->guessCacheKey()
        ];
        
        $config = array_merge($defaults, $this->config);
        
        $middleware = function (callable $handler) use ($config) {
            $chainLink = function (RequestInterface $request, array $options) use ($handler, $config) {
                $token = session($config['cache_key']);
                
                //TODO: Need to handle the possibility of an expired token.  May need to do that in the Retry middleware, and have it reach into an adapter that's configured here, and implements an interface that ensures the presence of a an 'authenticate' or 'refresh' method
                if (!$token) {
                    $token = session($config['cache_key']);
                    session([$config['cache_key'] => $token]);
                }
                
                return $token ?
                    $handler($request->withHeader('Authorization', "Bearer $token"), $options)
                    : $handler($request, $options);
            };
            
            return $chainLink;
        };
        
        return $middleware;
    }
}
