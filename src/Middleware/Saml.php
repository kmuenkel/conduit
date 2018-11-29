<?php

namespace Conduit\Middleware;

use Psr\Http\Message\RequestInterface;

class Saml extends BaseMiddleware
{
    /**
     * @return callable
     */
    public function getMiddleware() : callable
    {
        $defaults = [];
        $config = array_merge($defaults, $this->config);
    
        $middleware = function (callable $handler) use ($config) {
            $chainLink = function (RequestInterface $request, array $options) use ($handler, $config) {
                $token = cache($config['cache_key']);
                
                $getToken = function () use ($config) {
                    //TODO: Finish this.  Need to parse SAML XML using the DOMDocument class.  May consider leveraging https://github.com/aacotroneo/laravel-saml2
                    $token = '';
                    cache([$config['cache_key'] => $token]);
                    return $token;
                };
                
                $token = $token ?: $getToken();
                
                return $handler($request->withHeader('Authorization', 'SSWS ' . $token), $options);
            };
            
            return $chainLink;
        };
        
        return $middleware;
    }
}
