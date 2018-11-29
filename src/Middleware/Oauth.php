<?php

namespace Conduit\Middleware;

use Conduit\Drivers\Driver;
use Psr\Http\Message\RequestInterface;
use League\OAuth2\Client\Provider\GenericProvider;

class Oauth extends BaseMiddleware
{
    /**
     * @return callable
     */
    public function getMiddleware() : callable
    {
        $defaults = [
            'cache_key' => $this->guessCacheKey(),
            'http_client' => app(Driver::class, ['serviceConfig' => $this->config])->getClient()
        ];
        
        $config = array_merge($defaults, $this->config);
        
        $middleware = function (callable $handler) use ($config) {
            $chainLink = function (RequestInterface $request, array $options) use ($handler, $config) {
                $token = cache($config['cache_key']);
                
                if ($token && $token->hasExpired()) {
                    $config['grant_type'] = 'refresh_token';
                    $config['refresh_token'] = $token->getRefreshToken();
                    cache()->forget($config['cache_key']);
                    $token = null;
                }
                
                $getToken = function () use ($config) {
                    $options = [
                        'clientId' => $config['client_id'],
                        'clientSecret' => $config['client_secret'],
                        'urlAuthorize' => $config['authorization_url'],
                        'urlAccessToken' => $config['access_token_url'],
                        'urlResourceOwnerDetails' => $config['resource_owner_url'],
                    ];
                    
                    $collaborators = [
                        'httpClient' => $config['http_client']
                    ];
                    
                    $provider = app(GenericProvider::class, compact('options', 'collaborators'));
                    
                    $grantParameters = [
                        'refresh_token' => ['refresh_token', 'scope'],
                        'authorization_code' => ['redirect_uri', 'scope'],
                        'password' => ['username', 'password', 'scope'],
                        'client_credentials' => ['scope']
                    ];
                    
                    $options = array_only($config, $grantParameters[$config['grant_type']]);
                    $token = $provider->getAccessToken($config['grant_type'], $options);
                    $expiration = date('Y-m-d H:i:s', strtotime($token->getExpires().' - 1 MINUTE'));
                    $token = $token->jsonSerialize();
                    cache([$config['cache_key'] => $token], $expiration);
                    
                    return $token;
                };
                
                $token = $token ?: $getToken();
                
                return $handler($request->withHeader('Authorization', 'Bearer ' . $token['access_token']), $options);
            };
            
            return $chainLink;
        };
        
        return $middleware;
    }
}
