<?php

namespace Conduit\Testing;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Handler\MockHandler;
use Psr\Http\Message\RequestInterface;

class MockGuzzleHandler extends MockHandler
{
    /**
     * @var array
     */
    protected $history = [];
    
    /**
     * @var array
     */
    protected $responses = [];
    
    /**
     * MockGuzzle constructor.
     * @param array|null $queue
     * @param callable|null $onFulfilled
     * @param callable|null $onRejected
     */
    public function __construct(array $queue = null, callable $onFulfilled = null, callable $onRejected = null)
    {
        parent::__construct($queue, $onFulfilled, $onRejected);
        $this->setDefault();
    }
    
    /**
     * @param RequestInterface $request
     * @param array $options
     * @return \GuzzleHttp\Promise\PromiseInterface
     */
    public function __invoke(RequestInterface $request, array $options)
    {
        if (!$this->count()) {
            $matchedResponse = $queuedResponse = $defaultResponse = null;
            foreach ($this->responses as $index => $response) {
                if (!is_array($response)) {
                    $queuedResponse = $queuedResponse ?: [$index => $response];
                    //TODO: Pass the iteration here too.  count the number of times an endpoint is hit
                } elseif (is_callable($response['request']) && $response['request']($request, $options)) {
                    $matchedResponse = $response['response'];
                } elseif ($response['request'] === true) {
                    $defaultResponse = $response['response'];
                }
            }
            
            if (!$matchedResponse) {
                if ($queuedResponse) {
                    $index = key($queuedResponse);
                    $matchedResponse = current($queuedResponse);
                    unset($this->responses[$index]);
                } elseif ($defaultResponse) {
                    $matchedResponse = $defaultResponse;
                }
            }
            
            parent::append($matchedResponse);
        }
        
        $response = parent::__invoke($request, $options);
        
        $this->history[] = [
            'request' => $request,
            'response' => $response->wait()
        ];
        
        return $response;
    }
    
    public function append()
    {
        $args = func_get_args();
        foreach ($args as $response) {
            if (is_array($response)) {
                if (!array_keys_exist(['request', 'response'], $response, true)) {
                    throw new \InvalidArgumentException("Array responses must have a 'request' and 'response' key.");
                }
                
                if ($response['request'] === true) {
                    $index = collect($this->responses)->where('request', true)->keys()->first();
                    if (!is_null($index)) {
                        $this->responses[$index] = $response;
                        continue;
                    }
                }
            }
            
            $this->responses[] = $response;
        }
    }
    
    /**
     * @return array
     */
    public function getHistory()
    {
        return $this->history;
    }
    
    /**
     * void
     */
    public function clearHistory()
    {
        $this->history = [];
    }
    
    /**
     * @param Response|false $response
     */
    public function setDefault(Response $response = null)
    {
        if ($response === false) {
            $index = collect($this->responses)->whereStrict('request', true)->keys()->first();
            if (!is_null($index)) {
                unset($this->responses[$index]);
                return;
            }
        }
        
        $response = $response ?: app(Response::class, [
            'status' => 204,
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode(['dummy-response'])
        ]);
        
        $this->append([
            'request' => true,
            'response' => $response
        ]);
    }
}
