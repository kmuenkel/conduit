<?php

namespace Conduit\Transformers;

use DOMDocument;
use ErrorException;

/**
 * Class JsonTransformer
 * @package Conduit\Transformers
 */
class HtmlResponse extends XmlResponse
{
    /**
     * {@inheritDoc}
     */
    protected function load(DOMDocument $doc, string $body)
    {
        try {
            $doc->loadHTML($body);
        } catch (ErrorException $error) {
            if (preg_match("/ID .+ already defined in Entity/", $error->getMessage())) {
                @$doc->loadHTML($body);
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function __toString(): string
    {
        return $this->content->document->saveHTML();
    }
}
