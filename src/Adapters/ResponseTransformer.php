<?php

namespace Conduit\Adapters;

interface ResponseTransformer
{
    /**
     * @param array $response
     * @param null $name
     * @return mixed
     */
    public function transform(array $response, $name = null);
}
