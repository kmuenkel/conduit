<?php

namespace Conduit\Middleware;

use Psr\Http\Message\RequestInterface;

class Session extends BaseMiddleware
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
                $getSession = function () use ($config) {
                    //TODO:  May need to create a new adapter interface that enforces the presence of an 'authenticate' method
                    $sessionCookies = $config['adapter']->authenticate($config['credentials']);
                    $sessionCookie = (array_keys_exist($config['sessionCookie'], $sessionCookies) && date('c', $sessionCookies[$config['sessionCookie']]['Expires'] < date('c'))) ?
                        $sessionCookies[$config['sessionCookie']]['Value'] : null;
                    return $sessionCookie;
                };
                
                $session = cache($config['cache_key']);
                
                if (!$session) {
                    $session = $getSession();
                    cache([$config['cache_key'] => $session]);
                }
                
                return $handler($request, $options);
            };
            
            return $chainLink;
        };
        
        return $middleware;
    }
}
