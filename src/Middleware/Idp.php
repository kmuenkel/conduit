<?php

namespace Conduit\Middleware;

use Cache;
use Psr\Http\Message\RequestInterface;

class Idp extends BaseMiddleware
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
                $getToken = function () use ($config) {
                    //TODO: It may in fact be possible to leverage Okta's internal-API to produce a new SSWS token, by allowing Guzzle to retain the session cookies intended for the browser and apply them to subsequent calls.  See: https://github.com/guzzle/guzzle/issues/1400
                    //The $config array must include a reference to a separate OktaSessionAdapter, and the credentials to be passed to it.
                    //Instantiate that adapter with that credential array, ultimately derived from the service config.  May want to create a new interface for it that enforces the presence of an 'authenticate' method.
                    //Call the login method on the adapter.  It should capture the session cookie and apply it to subsequent calls.
                    //The adapter should include support for the following calls:
                    //(GET)https://sasr-admin.oktapreview.com/api/internal/tokens?expand=user: List all current tokens.  Look for the one that goes by the name that this class needs.  Hopefully, if such a token exists, it would already be in cache here.  If not, it will need to be replaced.
                    //(POST)https://sasr-admin.oktapreview.com/api/internal/tokens/{id}/revoke, send the entire JSON token object from the list.
                    //(POST)https://sasr-admin.oktapreview.com/api/internal/tokens?expand=user, send {"name":"Key Name"} to create a new one
                    //Check for status=ACTIVE
                    
                    return $config['api_token'];
                };
                
                $token = cache($config['cache_key']);
                //TODO: deactivate token caching so they can be swaped out for debugging
                $token = null;
                
                // Token 00gfwjgL5RzLUve1auWtfi7K-vL9_SYNMRiDTS5den should now be expired.  Use it to experiment on what the response looks like, and add it as a condition for replacing it.
                if (!$token) {
                    $token = $getToken();
                    Cache::store('file')->forever($config['cache_key'], $token);
                }
                
                return $handler($request->withHeader('Authorization', "SSWS $token"), $options);
            };
            
            return $chainLink;
        };
        
        return $middleware;
    }
}
