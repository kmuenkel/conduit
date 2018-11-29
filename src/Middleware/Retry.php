<?php

namespace Conduit\Middleware;

use Conduit;
use GuzzleHttp\Middleware;
use GuzzleHttp\RetryMiddleware;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use GuzzleHttp\Exception\RequestException as GuzzleRequestException;

class Retry extends BaseMiddleware
{
    public static $maxRetries = 3;
    
    /**
     * @return callable
     */
    public function getMiddleware() : callable
    {
        $limitReached = function ($retries, GuzzleRequest $request, GuzzleResponse $response) {
            if (!$retries && $response->getStatusCode() == 503) {
                $headers = Conduit\parse_http_headers($response->getHeaders());
                if (array_key_exists('x-rate-limit-remaining', $headers) && $headers['x-rate-limit-remaining'] < 1) {
                    return true;
                }
            }
            return false;
        };
        
        $defaults = [
            'policies' => [
                //TODO: This needs to be enacted only if the oauth middleware is detected.  It then needs to deleted the current token and re-run the OAuth's attempt to get one, rather than permit it to rely on the cached one.
//                'authentication' => [
//                    'rules' => [
//                        'expired' => function ($retries, GuzzleRequest $request, GuzzleResponse $response) {
//                            return (!$retries && $response->getStatusCode() == 401);
//                        }
//                    ]
//                ],
                'rate_limit' => [
                    'rules' => [
                        'limit_reached' => $limitReached
                    ],
                    'delay' => function ($retries, GuzzleRequest $request, GuzzleResponse $response) use ($limitReached) {
                        if ($limitReached($retries, $request, $response)) {
                            $headers = Conduit\parse_http_headers($response->getHeaders());
                            $secondsTillReset = $headers['x-rate-limit-reset'] - strtotime('now');
                            return $secondsTillReset * 1000;
                        }
                        return RetryMiddleware::exponentialDelay($retries);
                    }
                ]
            ],
            'cache_key' => get_class($this)
        ];
        
        $config = array_merge_recursive($defaults, $this->config);
        
        $middleware = Middleware::retry(function ($retries, GuzzleRequest $request, GuzzleResponse $response = null, GuzzleRequestException $error = null) use ($config) {
            $permitRetry = false;
            
            if ($response && $retries < self::$maxRetries) {
                $permitRetry = false;
                $delay = null;
                foreach ($config['policies'] as $policy) {
                    $permitPolicy = true;
                    foreach ($policy['rules'] as $rule) {
                        $permitPolicy &= $rule($retries, $request, $response, $error);
                    }
                    $permitRetry |= $permitPolicy;
                }
                
                if ($permitRetry) {
                    cache()->forget($config['cache_key']);
                    $uri = Conduit\parse_http_request($request)['uri'];
                    logger()->warning('Uri '.$uri['full'].' responded '.$response->getStatusCode().'. Retrying request for access token in '.get_class($this).', number '.($retries + 1));
                }
            }
            
            return $permitRetry;
        });
        
        return $middleware;
    }
}
