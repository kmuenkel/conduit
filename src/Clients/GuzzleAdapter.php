<?php

namespace Conduit\Clients;

use Conduit\Drivers\Driver;
use Conduit\Testing\MockGuzzleHandler;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\HandlerStack;
use Http\Adapter\Guzzle6\Client as BaseGuzzleAdapter;
use Http\Adapter\Guzzle6\Promise;
use Psr\Http\Message\RequestInterface;

/**
 * Geocoder\Laravel\ProviderAndDumperAggregator::getAdapterClass() doesn't offer the opportunity to inject custom
 * configurations into the HttpClient, such as the Guzzle MockHandler for testing.  So the only way to get it in there
 * is to offer that ability via this this override class.
 * Make the test alters the 'geocoder.adapter' definition to refer to this class.
 *
 * Class GuzzleAdapter
 * @package Conduit\Clients
 */
class GuzzleAdapter extends BaseGuzzleAdapter
{
    /**
     * @var Callable
     */
    protected $handler;
    
    /**
     * @var bool
     */
    public static $withoutAdapterMiddleware = false;
    
    /**
     * MockGuzzleAdapter constructor.
     * @param ClientInterface|null $client
     */
    public function __construct(ClientInterface $client = null)
    {
        parent::__construct($this->getClient());
    }
    
    /**
     * @param array $config
     * @return ClientInterface
     */
    public function getClient(array $config = []) : ClientInterface
    {
        if (!isset($config['handler']) || !self::$withoutAdapterMiddleware) {
            $config['handler'] = HandlerStack::create();
        }
        $config['handler']->setHandler($this->getHandler());
        $client = app(Driver::class, ['serviceConfig' => $config])->getGuzzleClient();
        
        return $client;
    }
    
    /**
     * @return Callable
     */
    public function getHandler() : callable
    {
        return $this->handler ?: HandlerStack::create();
    }
    
    /**
     * @param callable $handler
     */
    public function setHandler(callable $handler)
    {
        $this->handler = $handler;
    }
    
    /**
     * {@inheritdoc}
     */
    public function sendAsyncRequest(RequestInterface $request)
    {
        $promise = $this->getClient()->sendAsync($request);
        
        return new Promise($promise, $request);
    }
    
    /**
     * @param mixed $response
     * @return bool
     */
    public function append($response)
    {
        $handler = $this->getHandler();
        if ($handler instanceof MockGuzzleHandler) {
            $handler->append($response);
            $this->handler = $handler;
            
            return true;
        }
        
        return false;
    }
}
