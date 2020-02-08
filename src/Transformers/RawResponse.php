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
     * @return $this
     */
    public function __invoke(ResponseInterface $response): ResponseStruct
    {
        $this->content = $response->getBody();

        return $this;
    }
}
