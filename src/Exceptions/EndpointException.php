<?php

namespace Conduit\Exceptions;

use RuntimeException;
use Psr\Http\Message\ResponseInterface;
use Conduit\Transformers\ResponseStruct;

/**
 * Class EndpointException
 * @package Conduit\Exceptions
 */
class EndpointException extends RuntimeException
{
    /**
     * @var ResponseStruct|null
     */
    protected $content = null;

    /**
     * @var ResponseInterface|null
     */
    protected $rawContent = null;

    /**
     * @param ResponseStruct $content
     * @return $this
     */
    public function setContent(ResponseStruct $content)
    {
        $this->content = $content;

        return $this;
    }

    /**
     * @param ResponseInterface $response
     * @return $this
     */
    public function setRaw(ResponseInterface $response)
    {
        $this->rawContent = $response;

        return $this;
    }

    /**
     * @return ResponseStruct
     */
    public function getContent(): ResponseStruct
    {
        return $this->content;
    }

    /**
     * @return ResponseInterface|null
     */
    public function getRaw(): ?ResponseInterface
    {
        return $this->rawContent;
    }
}