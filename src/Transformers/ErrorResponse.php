<?php

namespace Conduit\Transformers;

use Exception;

/**
 * Class ErrorResponse
 * @package Conduit\Transformers
 */
class ErrorResponse extends RawResponse
{
    /**
     * @var Exception
     */
    protected $error;

    /**
     * @param Exception $error
     * @return $this
     */
    public function setError(Exception $error)
    {
        $this->error = $error;

        return $this;
    }

    /**
     * @return Exception
     */
    public function getError(): Exception
    {
        return $this->error;
    }
}
