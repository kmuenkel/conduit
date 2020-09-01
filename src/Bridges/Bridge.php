<?php

namespace Conduit\Bridges;

use RuntimeException;
use Conduit\Adapters\Adapter;
use Psr\Http\Message\ResponseInterface;

/**
 * Interface Bridge
 * @package Conduit\Bridges
 */
interface Bridge
{
    /**
     * @return ResponseInterface
     */
    public function send();

    /**
     * @return RuntimeException|null
     */
    public function getError(): ?RuntimeException;

    /**
     * @param Adapter $adapter
     * @return Bridge
     */
    public function setAdapter(Adapter $adapter): Bridge;

    /**
     * @return Adapter
     */
    public function getAdapter(): Adapter;
}
