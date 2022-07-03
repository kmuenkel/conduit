<?php

namespace Conduit\Exceptions;

use RuntimeException;
use Conduit\Adapters\Adapter;
use Psr\Http\Message\ResponseInterface;
use Conduit\Transformers\ResponseStruct;

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
     * @var Adapter|null
     */
    protected ?Adapter $adapter = null;

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
     * @param Adapter|null $adapter
     * @return $this
     */
    public function setAdapter(?Adapter $adapter): self
    {
        $this->adapter = $adapter;

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

    /**
     * @return Adapter|null
     */
    public function getAdapter(): ?Adapter
    {
        return $this->adapter;
    }
}
