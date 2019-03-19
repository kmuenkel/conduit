<?php

namespace Conduit\Testing;

use GuzzleHttp\Psr7\Uri;
use Conduit\Drivers\Driver;
use Conduit\Drivers\GuzzleDriver;

/**
 * Class TestDriver
 * @package Conduit\Testing
 */
class TestDriver implements Driver
{
    /**
     * {@inheritdoc}
     */
    public function send($uri, $method = 'get', array $parameters = [], array $headers = [], array $cookies = []) : array
    {
        $method = strtoupper($method);
        $args = get_defined_vars();
        $guzzleDriver = app(GuzzleDriver::class);
        $client = $guzzleDriver->getGuzzleClient();
        $config = $client->getConfig();
        /** @var Uri $uri */
        $baseUri = array_get($config, 'base_uri', app(Uri::class));
        $baseUri = (string)$baseUri;
    
        $resolvedParams = $guzzleDriver->generateParameters($uri, $method, $parameters);
        
        $args = array_merge($args, $resolvedParams);
        $args['uri'] = $baseUri.$args['uri'];
        //TODO: remove params since theyre parsed now
        
        ddd($args);
    }
    
    /**
     * {@inheritdoc}
     */
    public function getClient()
    {
        //
    }
}
