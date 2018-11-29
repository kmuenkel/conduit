<?php

namespace Conduit\Testing;

use Conduit;
use Illuminate\Support\Collection;
use Conduit\Clients\GuzzleAdapter;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Contracts\Container\Container;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use GuzzleHttp\ClientInterface as GuzzleClientInterface;

trait MockGuzzle
{
    /**
     * @var GuzzleAdapter
     */
    protected $mockGuzzle;
    
    /**
     * This should be called by your test's setUp() method
     */
    public function setupMockHandler()
    {
        $this->mockGuzzle = app(GuzzleAdapter::class);
        $mockHandler = app(MockGuzzleHandler::class);
        $this->mockGuzzle->setHandler($mockHandler);
        config(['geocoder.adapter' => GuzzleAdapter::class]);
        if (method_exists($this, 'mockGeolocation')) {
            $this->mockGeolocation();
            $this->appendResponse([
                'uri.host' => 'maps.googleapis.com',
                'uri.path' => '/maps/api/geocode/json'
            ], $this->guzzleResponses['geolocation']);
            $this->appendResponse([
                'uri.host' => 'maps.googleapis.com',
                'uri.path' => '/maps/api/timezone/json'
            ], $this->guzzleResponses['timezone']);
        }
        
        config(['guzzle.debug' => true, 'guzzle.verify' => false]);
        $guzzleClosure = function (Container $app, array $params = []) use ($mockHandler) {
            return $this->mockGuzzle->getClient(array_merge(
                $app->make('config')->get('guzzle', []),
                array_get($params, 'config', [])
            ));
        };
        app()->bind(GuzzleClient::class, $guzzleClosure);
        app()->bind(GuzzleClientInterface::class, $guzzleClosure);
        app()->bind(GuzzleAdapter::class, function (Container $app, array $params = []) {
            return $this->mockGuzzle;
        });
    }
    
    /**
     * @return Collection
     */
    public function getGuzzleHistory()
    {
        /** @var MockGuzzleHandler $handler */
        $handler = $this->mockGuzzle->getHandler();
        $history = method_exists($handler, 'getHistory') ?
            $handler->getHistory() : Conduit\Middleware\Logging::getTransactionLog();
        
        foreach ($history as $index => $communication) {
            $history[$index] = [
                'request' => Conduit\parse_http_request($communication['request']),
                'response' => Conduit\parse_http_response($communication['response'])
            ];
        }
        
        return collect($history);
    }
    
    /**
     * void
     */
    public function clearGuzzleHistory()
    {
        /** @var MockGuzzleHandler $handler */
        $handler = $this->mockGuzzle->getHandler();
        $handler->clearHistory();
    }
    
    /**
     * @param array $conditions
     * @param mixed $responseBody
     * @param int $responseCode
     * @param array $responseHeaders
     * @param array $responseCookies
     */
    public function appendResponse(array $conditions, $responseBody = [], $responseCode = 200, $responseHeaders = [], array $responseCookies = [])
    {
        $contentType = Conduit\guess_content_type($responseBody);
        $responseBody = Conduit\prime_http_content($responseBody, $contentType, true);
        
        $responseHeaders = array_change_key_case($responseHeaders);
        $responseHeaders = array_merge(['content-type' => $contentType], $responseHeaders);
        
        $this->mockGuzzle->append([
            'request' => function (GuzzleRequest $request, array $options = []) use ($conditions) {
                $conditions = array_dot($conditions);
                $request = Conduit\parse_http_request($request);
                $request = array_dot($request);
                $passes = true;
                foreach ($conditions as $field => $check) {
                    if ($field == 'uri.path') {
                        $check = '/'.trim($check, '/');
                    }
                    $passes &= (array_key_exists($field, $request) && strcasecmp($request[$field], $check) === 0);
                }
                
                return $passes;
            },
            'response' => function (GuzzleRequest $request, array $options = []) use (
                $responseBody,
                $responseCode,
                $responseHeaders,
                $responseCookies
            ) {
                $responseHeaders = array_change_key_case($responseHeaders);
                
                //https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Set-Cookie
                $cookies = [];
                foreach ($responseCookies as $name => $value) {
                    #$expiration = gmdate('D, d M Y H:i:s \G\M\T', strtotime('+ 1 MINUTE'));
                    #$expiration = strtotime('+ 1 MINUTE');
                    $expiration = '';
                    #$maxAge = 1;
                    $maxAge = 0;
                    #$domain = config('app.url');
                    $domain = '';
                    $path = '/';
                    if (is_array($value)) {
                        extract($value);
                    }
                    $cookie = htmlentities($name).'='.htmlentities($value).';'
                        . ($expiration ? ' Expires='.htmlentities($expiration).';' : '')
                        . ($maxAge ? ' Max-Age='.htmlentities($maxAge).';' : '')
                        . ($domain ? ' Domain='.htmlentities($domain).';' : '')
                        . ($path ? ' Path='.htmlentities($path).';' : '');
                    $cookies[] = rtrim($cookie, ';');
                }
                $currentCookies = (array)array_get($responseHeaders, 'set-cookie', []);
                $currentCookies = array_merge($currentCookies, $cookies);
                if (!empty($currentCookies)) {
                    $responseHeaders['set-cookie'] = $currentCookies;
                }
                
                return app(GuzzleResponse::class, [
                    'status' => $responseCode,
                    'headers' => $responseHeaders,
                    'body' => $responseBody
                ]);
            }
        ]);
    }
}
