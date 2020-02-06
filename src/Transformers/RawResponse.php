<?php

namespace Conduit\Transformers;

use Psr\Http\Message\ResponseInterface;

/**
 * Class RawResponse
 * @package Conduit\Transformers
 */
class RawResponse extends ResponseStruct
{
    use StubResponse;

    /**
     * @param ResponseInterface $response
     * @return ResponseInterface
     */
    public function __invoke(ResponseInterface $response)
    {
        return $this->content = $response;
    }
}
