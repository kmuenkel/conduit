<?php

namespace Conduit\Transformers;

use DOMXpath;
use DOMDocument;
use Psr\Http\Message\ResponseInterface;

/**
 * Class JsonTransformer
 * @package Conduit\Transformers
 */
class HtmlResponse extends XmlResponse
{
    /**
     * @param ResponseInterface $response
     * @return DOMXpath
     */
    public function __invoke(ResponseInterface $response)
    {
        $body = $response->getBody();
        $doc = app(DOMDocument::class, ['version' => $this->version, 'encoding' => $this->encoding]);
        $doc->loadHTML($body);
        $dom = app(DOMXpath::class, compact('doc'));

        return $dom;
    }

    /**
     * {@inheritDoc}
     */
    public function __toString()
    {
        return $this->content->document->saveHTML();
    }
}
