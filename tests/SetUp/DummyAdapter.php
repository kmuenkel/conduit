<?php

namespace Conduit\Tests\SetUp;

use Conduit\Adapters\BaseAdapter;
use Conduit\Exceptions\BridgeTransactionException;

/**
 * Class DummyAdapter
 * @package Tests\SetUp
 */
class DummyAdapter extends BaseAdapter
{
    /**
     * @return array
     * @throws BridgeTransactionException
     */
    public function dummyEndpoint()
    {
        $uri = '{pathParam}';
        $method = 'POST';
        $parameters = [
            'path' => [
                'pathParam' => 'pathValue'
            ],
            'query' => [
                'queryParam' => 'queryValue'
            ],
            'body' => [
                'bodyParam' => 'bodyValue'
            ]
        ];
        
        $headers = [
            'Authorization' => 'Bearer 1234'
        ];
        
        $cookies = [
            'session' => 'session_token'
        ];
        
        return $this->send($uri, $method, $parameters, $headers, $cookies);
    }
}
