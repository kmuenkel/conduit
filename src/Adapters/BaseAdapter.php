<?php

namespace Conduit\Adapters;

use Conduit\Drivers\Driver;
use Conduit\Exceptions\BridgeTransactionException;

/**
 * Class BaseAdapter
 * @package Conduit\Adapters
 */
class BaseAdapter {
    
    /**
     * @var array
     */
    protected $config = [];
    
    /**
     * @var Driver
     */
    protected $driver;
    
    /**
     * @var ResponseTransformer|null
     */
    protected static $transformer;
    
    /**
     * BaseAdapter constructor.
     * @param array $config
     * @param ResponseTransformer|null $transformer
     */
    public function __construct(array $config = [], ResponseTransformer $transformer = null)
    {
        $this->setConfig($config);
        self::$transformer = $transformer;
    }
    
    /**
     * @param array $config
     */
    public function setConfig(array $config)
    {
        $this->config = !empty($config) ? $config : config('services.'.get_class($this), []);
        if (!$this->driver || array_get($this->config, 'disk')) {
            $driverClass = $this->driver ? get_class($this->driver) : Driver::class;
            $this->driver = app($driverClass, ['serviceConfig' => $this->config]);
        }
    }
    
    /**
     * TODO: Consider setting this up with support for an optional input and output transformer, that can be bound to a given adapter in the service provider
     *
     * @param ResponseTransformer $transformer
     */
    public static function setTransformer(ResponseTransformer $transformer)
    {
        self::$transformer = $transformer;
    }
    
    /**
     * @param Driver $driver
     * @return $this
     */
    public function setDriver(Driver $driver)
    {
        $this->driver = $driver;
        
        return $this;
    }
    
    /**
     * @return Driver
     */
    public function getDriver()
    {
        return $this->driver;
    }
    
    /**
     * @param string $uri
     * @param string $method
     * @param array $parameters
     * @param array $headers
     * @param array $cookies
     * @return array
     * @throws BridgeTransactionException
     */
    public function send($uri, $method = 'get', $parameters = [], array $headers = [], array $cookies = [])
    {
        return $this->driver->send($uri, $method, $parameters, $headers, $cookies);
    }
}
