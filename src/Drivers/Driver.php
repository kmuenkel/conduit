<?php

namespace Conduit\Drivers;

use Conduit\Exceptions\BridgeTransactionException;

interface Driver
{
    //TODO: After properly abstracting Guzzle out from the only current implementation of this interface, add a definition for the constructor, so the BaseAdapter class' use of its parameters can be more reliable
    
    /**
     * @param $uri
     * @param string $method
     * @param array $parameters
     * @return array
     * @throws BridgeTransactionException
     */
    public function send($uri, $method = 'get', array $parameters = []) : array;
    
    /**
     * @return mixed
     */
    public function getClient();
    
    //TODO: Add a setConfig method
}
